<?php
// a_qualitycheck.php -- HotCRP quality check assignment helper classes
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class QualityCheck_Assignable extends Assignable {
    /** @var ?int */
    public $cid;
    /** @var ?int */
    public $_review_id;
    /** @param ?int $pid
     * @param ?int $cid */
    function __construct($pid, $cid, $review_id = null) {
        $this->pid = $pid;
        $this->cid = $cid;
        $this->_review_id = $review_id;
    }
    /** @return string */
    function type() {
        return "qualitycheck";
    }
    /** @return self */
    function fresh() {
        return new QualityCheck_Assignable($this->pid, $this->cid);
    }
}

class QualityCheck_AssignmentParser extends AssignmentParser {
    function __construct(Conf $conf, $aj) {
        parent::__construct("qualitycheck");
    }
    function load_state(AssignmentState $state) {
        if ($state->mark_type("qualitycheck", ["pid", "cid"], "QualityCheck_Assigner::make")) {
            $result = $state->conf->qe(
                "SELECT paperId, contactId, reviewId FROM ReviewQualityCheck WHERE paperId?a",
                $state->paper_ids()
            );
            while (($row = $result->fetch_row())) {
                $state->load(new QualityCheck_Assignable((int) $row[0], (int) $row[1], (int) $row[2]));
            }
            Dbl::free($result);
        }
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (!$state->user->privChair && !$state->user->is_track_manager()) {
            return false;
        }
        return true;
    }
    /** @param CsvRow $req */
    function user_universe($req, AssignmentState $state) {
        return "pc";
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        $qa = new QualityCheck_Assignable($prow->paperId, $contact->contactId);
        $state->add($qa);
        return true;
    }
}

class QualityCheck_Assigner extends Assigner {
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        return new QualityCheck_Assigner($item, $state);
    }
    function unparse_description() {
        return "quality check";
    }
    function unparse_display(AssignmentSet $aset) {
        $t = $aset->user->reviewer_html_for($this->contact);
        if ($this->item->deleted()) {
            $t = '<del>' . $t . ' quality check</del>';
        } else if (!$this->item->existed()) {
            $t .= ' <span class="hint">quality check</span>';
        }
        return $t;
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $x = [
            "pid" => $this->pid,
            "action" => "qualitycheck",
            "email" => $this->contact->email,
            "name" => $this->contact->name()
        ];
        $acsv->add($x);
    }
    function account(AssignmentSet $aset, AssignmentCountSet $deltarev) {
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["ReviewQualityCheck"] = "write";
    }
    function execute(AssignmentSet $aset) {
        $conf = $aset->conf;
        if ($this->item->deleted()) {
            $conf->qe(
                "DELETE FROM ReviewQualityCheck WHERE paperId=? AND contactId=?",
                $this->pid, $this->cid
            );
        } else if (!$this->item->existed()) {
            $reviews = [];
            $result = $conf->qe(
                "SELECT reviewId FROM PaperReview WHERE paperId=? AND reviewType<?",
                $this->pid, REVIEW_META
            );
            while (($row = $result->fetch_row())) {
                $reviews[] = (int) $row[0];
            }
            Dbl::free($result);

            $now = Conf::$now;
            foreach ($reviews as $rid) {
                $existing = $conf->qe(
                    "SELECT checkId FROM ReviewQualityCheck WHERE paperId=? AND reviewId=? AND contactId=?",
                    $this->pid, $rid, $this->cid
                );
                if ($existing->num_rows === 0) {
                    $conf->qe(
                        "INSERT INTO ReviewQualityCheck SET paperId=?, reviewId=?, contactId=?, status=0, timeCreated=?, timeModified=?",
                        $this->pid, $rid, $this->cid, $now, $now
                    );
                }
                Dbl::free($existing);
            }
        }
    }
}
