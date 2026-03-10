<?php
// a_reviewquality.php -- HotCRP review quality check assignment
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ReviewQuality_Assignable extends Assignable {
    /** @var ?int */
    public $_cid;
    /** @var ?int */
    public $_rid;
    /** @param ?int $pid
     * @param ?int $cid
     * @param ?int $rid */
    function __construct($pid = null, $cid = null, $rid = null) {
        $this->type = "reviewqualitycheck";
        $this->pid = $pid;
        $this->_cid = $cid;
        $this->_rid = $rid;
    }
    /** @return self */
    function fresh() {
        return new ReviewQuality_Assignable($this->pid);
    }
}

class ReviewQuality_AssignmentParser extends AssignmentParser {
    private $remove;

    function __construct(Conf $conf, $aj) {
        parent::__construct($aj->name);
        $this->remove = $aj->remove ?? false;
    }

    function load_state(AssignmentState $state) {
        if (!$state->mark_type("reviewqualitycheck", ["pid", "_cid", "_rid"], "ReviewQuality_Assigner::make")) {
            return;
        }
        $result = $state->conf->qe("select paperId, contactId, reviewId from ReviewQualityCheck");
        while ($result && ($row = $result->fetch_object())) {
            $state->load(new ReviewQuality_Assignable(
                (int) $row->paperId,
                (int) $row->contactId,
                (int) $row->reviewId
            ));
        }
        Dbl::free($result);
    }

    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (!$state->user->can_assign_review_quality_check()) {
            return new AssignmentError("<0>Only chairs can assign review quality checks");
        }
        return true;
    }

    function user_universe($req, AssignmentState $state) {
        return "pc";
    }

    function allow_user(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        return true;
    }

    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        $reviewId = $req["review_id"] ?? null;
        if (!$reviewId) {
            $reviews = $prow->reviews_as_list();
            if (empty($reviews)) {
                $state->error("<0>No reviews found for submission #{$prow->paperId}");
                return true;
            }
            foreach ($reviews as $rrow) {
                $existing = $state->query(new ReviewQuality_Assignable($prow->paperId, $contact->contactId, $rrow->reviewId));
                if (empty($existing) && !$this->remove) {
                    $state->add(new ReviewQuality_Assignable($prow->paperId, $contact->contactId, $rrow->reviewId));
                } else if (!empty($existing) && $this->remove) {
                    $state->remove($existing[0]);
                }
            }
        } else {
            $reviewId = (int) $reviewId;
            $rrow = $prow->review_by_id($reviewId);
            if (!$rrow) {
                $state->error("<0>Review #{$reviewId} not found for submission #{$prow->paperId}");
                return true;
            }
            $existing = $state->query(new ReviewQuality_Assignable($prow->paperId, $contact->contactId, $reviewId));
            if (empty($existing) && !$this->remove) {
                $state->add(new ReviewQuality_Assignable($prow->paperId, $contact->contactId, $reviewId));
            } else if (!empty($existing) && $this->remove) {
                $state->remove($existing[0]);
            }
        }
        return true;
    }
}

class ReviewQuality_Assigner extends Assigner {
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
    }

    static function make(AssignmentItem $item, AssignmentState $state) {
        return new ReviewQuality_Assigner($item, $state);
    }

    function unparse_description() {
        return "review quality check";
    }

    function unparse_display(AssignmentSet $aset) {
        $t = $aset->user->reviewer_html_for($this->contact);
        if ($this->item->deleted()) {
            $t = '<del>' . $t . ' (quality check)</del>';
        } else if ($this->item->existed()) {
            $t .= ' (quality check, unchanged)';
        } else {
            $t .= ' <span class="new">(quality check)</span>';
        }
        return $t;
    }

    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $x = ["pid" => $this->pid, "action" => "reviewqualitycheck"];
        if ($this->contact) {
            $x["email"] = $this->contact->email;
        }
        $x["review_id"] = $this->item["_rid"] ?? "";
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
            $conf->qe("delete from ReviewQualityCheck where paperId=? and contactId=? and reviewId=?",
                $this->pid, $this->item["_cid"], $this->item["_rid"]);
        } else if (!$this->item->existed()) {
            $conf->qe("insert into ReviewQualityCheck set paperId=?, reviewId=?, contactId=?, status=0, timeCreated=?, timeModified=?",
                $this->pid, $this->item["_rid"], $this->item["_cid"],
                Conf::$now, Conf::$now);
        }
    }
}
