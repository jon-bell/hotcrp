<?php
// reviewqualitycheckinfo.php -- HotCRP review quality check objects
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ReviewQualityCheckInfo implements JsonSerializable {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var ?PaperInfo */
    public $prow;

    /** @var int */
    public $reviewQualityCheckId = 0;
    /** @var int */
    public $paperId = 0;
    /** @var int */
    public $reviewId = 0;
    /** @var int */
    public $contactId = 0;
    /** @var int */
    public $status = 0;
    /** @var int */
    public $timeCreated = 0;
    /** @var int */
    public $timeModified = 0;
    /** @var ?string */
    private $tfields;
    /** @var ?string */
    private $sfields;
    /** @var ?string */
    private $data;
    /** @var ?object */
    private $_data;

    /** @var ?Contact */
    private $_checker;

    const STATUS_PENDING = 0;
    const STATUS_NEEDS_WORK = 1;
    const STATUS_IMPROVED = 2;
    const STATUS_APPROVED = 3;

    /** @var array<int,string> */
    static public $status_names = [
        0 => "pending",
        1 => "needs_work",
        2 => "improved",
        3 => "approved"
    ];

    /** @var array<string,int> */
    static public $status_map = [
        "pending" => 0,
        "needs_work" => 1,
        "needswork" => 1,
        "needs-work" => 1,
        "improved" => 2,
        "approved" => 3
    ];

    function __construct(?PaperInfo $prow = null, ?Conf $conf = null) {
        assert(($prow || $conf) && (!$prow || !$conf || $prow->conf === $conf));
        if ($prow) {
            $this->conf = $prow->conf;
            $this->prow = $prow;
            $this->paperId = $this->paperId ? : $prow->paperId;
        } else {
            $this->conf = $conf;
        }
    }

    private function fetch_incorporate() {
        $this->reviewQualityCheckId = (int) $this->reviewQualityCheckId;
        $this->paperId = (int) $this->paperId;
        $this->reviewId = (int) $this->reviewId;
        $this->contactId = (int) $this->contactId;
        $this->status = (int) $this->status;
        $this->timeCreated = (int) $this->timeCreated;
        $this->timeModified = (int) $this->timeModified;
    }

    /** @param Dbl_Result $result
     * @return ?ReviewQualityCheckInfo */
    static function fetch($result, ?PaperInfo $prow, ?Conf $conf) {
        $qc = $result->fetch_object("ReviewQualityCheckInfo", [$prow, $conf]);
        if ($qc) {
            $qc->fetch_incorporate();
        }
        return $qc;
    }

    function set_prow(PaperInfo $prow) {
        assert(!$this->prow && $this->paperId === $prow->paperId && $this->conf === $prow->conf);
        $this->prow = $prow;
    }

    /** @return string */
    function status_name() {
        return self::$status_names[$this->status] ?? "unknown";
    }

    /** @return string */
    function status_label_html() {
        $name = $this->status_name();
        $classes = [
            "pending" => "tag-gray",
            "needs_work" => "tag-red",
            "improved" => "tag-green",
            "approved" => "tag-blue"
        ];
        $cls = $classes[$name] ?? "tag-gray";
        return "<span class=\"badge {$cls}\">" . htmlspecialchars(str_replace('_', ' ', ucfirst($name))) . "</span>";
    }

    /** @return ?Contact */
    function checker() {
        if ($this->_checker === null && $this->contactId > 0) {
            $this->_checker = $this->conf->user_by_id($this->contactId);
        }
        return $this->_checker;
    }

    /** @return ?object */
    function data() {
        if ($this->_data === null && $this->data !== null) {
            $this->_data = json_decode($this->data);
        }
        return $this->_data;
    }

    /** @return object */
    function tfield_data() {
        if ($this->tfields !== null) {
            return json_decode($this->tfields) ?? (object) [];
        }
        return (object) [];
    }

    /** @return object */
    function sfield_data() {
        if ($this->sfields !== null) {
            return json_decode($this->sfields) ?? (object) [];
        }
        return (object) [];
    }

    /** @param string $field_id
     * @return mixed */
    function field_value($field_id) {
        if (str_starts_with($field_id, "t")) {
            $tf = $this->tfield_data();
            return $tf->$field_id ?? null;
        } else if (str_starts_with($field_id, "s")) {
            $sf = $this->sfield_data();
            return $sf->$field_id ?? null;
        }
        return null;
    }

    /** @param array $req
     * @return bool */
    function save($req, Contact $acting_user) {
        $user = $acting_user;
        if (!$user->contactId) {
            return false;
        }

        $status = $req["status"] ?? $this->status;
        if (is_string($status)) {
            $status = self::$status_map[$status] ?? $this->status;
        }

        $tfields = [];
        $sfields = [];
        $form_json = $this->conf->setting_json("review_quality_form");
        if (is_array($form_json)) {
            foreach ($form_json as $field) {
                $fid = $field->id ?? "";
                if (isset($req[$fid])) {
                    if (str_starts_with($fid, "t")) {
                        $tfields[$fid] = (string) $req[$fid];
                    } else if (str_starts_with($fid, "s")) {
                        $sfields[$fid] = (int) $req[$fid];
                    }
                }
            }
        }

        $tfields_json = !empty($tfields) ? json_encode((object) $tfields) : null;
        $sfields_json = !empty($sfields) ? json_encode((object) $sfields) : null;

        if (!$this->reviewQualityCheckId) {
            $result = $this->conf->qe(
                "insert into ReviewQualityCheck set paperId=?, reviewId=?, contactId=?, status=?, timeCreated=?, timeModified=?, tfields=?, sfields=?",
                $this->paperId, $this->reviewId, $user->contactId,
                $status, Conf::$now, Conf::$now,
                $tfields_json, $sfields_json
            );
            if ($result->affected_rows > 0) {
                $this->reviewQualityCheckId = $result->insert_id;
                $this->contactId = $user->contactId;
                $this->status = $status;
                $this->timeCreated = Conf::$now;
                $this->timeModified = Conf::$now;
                $this->tfields = $tfields_json;
                $this->sfields = $sfields_json;
                return true;
            }
            return false;
        } else {
            $result = $this->conf->qe(
                "update ReviewQualityCheck set status=?, timeModified=?, tfields=?, sfields=? where reviewQualityCheckId=?",
                $status, Conf::$now,
                $tfields_json, $sfields_json,
                $this->reviewQualityCheckId
            );
            if ($result->affected_rows >= 0) {
                $this->status = $status;
                $this->timeModified = Conf::$now;
                $this->tfields = $tfields_json;
                $this->sfields = $sfields_json;
                return true;
            }
            return false;
        }
    }

    /** @return object */
    function unparse_json(Contact $viewer) {
        $j = (object) [
            "id" => $this->reviewQualityCheckId,
            "pid" => $this->paperId,
            "rid" => $this->reviewId,
            "status" => $this->status_name(),
            "status_html" => $this->status_label_html(),
            "time_created" => $this->timeCreated,
            "time_modified" => $this->timeModified
        ];
        if ($viewer->can_view_review_quality_checker_identity($this)) {
            $checker = $this->checker();
            if ($checker) {
                $j->checker = $viewer->reviewer_html_for($checker);
                $j->checker_email = $checker->email;
            }
        }
        $form_json = $this->conf->setting_json("review_quality_form");
        if (is_array($form_json)) {
            $j->fields = (object) [];
            foreach ($form_json as $field) {
                $fid = $field->id ?? "";
                $val = $this->field_value($fid);
                if ($val !== null) {
                    $j->fields->$fid = $val;
                }
            }
        }
        return $j;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return $this->unparse_json(Contact::$main_user ?? new Contact(null, $this->conf));
    }

    /** @param int $status
     * @return bool */
    function update_status($status) {
        $result = $this->conf->qe(
            "update ReviewQualityCheck set status=?, timeModified=? where reviewQualityCheckId=?",
            $status, Conf::$now, $this->reviewQualityCheckId
        );
        if ($result->affected_rows >= 0) {
            $this->status = $status;
            $this->timeModified = Conf::$now;
            return true;
        }
        return false;
    }
}
