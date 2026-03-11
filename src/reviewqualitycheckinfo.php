<?php
// reviewqualitycheckinfo.php -- HotCRP helper class for review quality checks
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class ReviewQualityCheckInfo implements JsonSerializable {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var int */
    public $paperId = 0;
    /** @var int */
    public $reviewId = 0;
    /** @var int */
    public $checkId = 0;
    /** @var int */
    public $contactId = 0;
    /** @var int */
    public $status = 0;
    /** @var int */
    public $timeCreated = 0;
    /** @var int */
    public $timeModified = 0;
    /** @var ?int */
    public $timeResolved;

    /** @var int */
    public $s01 = 0;
    /** @var int */
    public $s02 = 0;
    /** @var int */
    public $s03 = 0;
    /** @var int */
    public $s04 = 0;
    /** @var int */
    public $s05 = 0;
    /** @var int */
    public $s06 = 0;
    /** @var ?string */
    public $tfields;
    /** @var ?string */
    public $sfields;

    /** @var ?array */
    private $_tfields_array;
    /** @var ?array */
    private $_sfields_array;

    const STATUS_PENDING = 0;
    const STATUS_NEEDS_WORK = 1;
    const STATUS_IMPROVED = 2;

    /** @var array<int,string> */
    static public $status_names = [
        0 => "Pending",
        1 => "Needs work",
        2 => "Improved"
    ];

    /** @var array<string,int> */
    static public $status_map = [
        "pending" => 0,
        "needs_work" => 1,
        "needswork" => 1,
        "improved" => 2
    ];


    function __construct(?Conf $conf = null) {
        if ($conf) {
            $this->conf = $conf;
        }
    }

    /** @return ReviewQualityCheckInfo */
    static function make_blank(Conf $conf) {
        $rqc = new ReviewQualityCheckInfo($conf);
        return $rqc;
    }

    /** @param object $x
     * @return ReviewQualityCheckInfo */
    static function fetch($x, Conf $conf) {
        $rqc = new ReviewQualityCheckInfo($conf);
        $rqc->paperId = (int) $x->paperId;
        $rqc->reviewId = (int) $x->reviewId;
        $rqc->checkId = (int) $x->checkId;
        $rqc->contactId = (int) $x->contactId;
        $rqc->status = (int) $x->status;
        $rqc->timeCreated = (int) $x->timeCreated;
        $rqc->timeModified = (int) $x->timeModified;
        $rqc->timeResolved = isset($x->timeResolved) ? (int) $x->timeResolved : null;
        $rqc->s01 = (int) $x->s01;
        $rqc->s02 = (int) $x->s02;
        $rqc->s03 = (int) $x->s03;
        $rqc->s04 = (int) $x->s04;
        $rqc->s05 = (int) $x->s05;
        $rqc->s06 = (int) $x->s06;
        $rqc->tfields = $x->tfields ?? null;
        $rqc->sfields = $x->sfields ?? null;
        return $rqc;
    }

    /** @return string */
    function status_name() {
        return self::$status_names[$this->status] ?? "Unknown";
    }

    /** @return bool */
    function is_needs_work() {
        return $this->status === self::STATUS_NEEDS_WORK;
    }

    /** @return bool */
    function is_improved() {
        return $this->status === self::STATUS_IMPROVED;
    }

    /** @return bool */
    function is_pending() {
        return $this->status === self::STATUS_PENDING;
    }

    /** @return array */
    function tfields_array() {
        if ($this->_tfields_array === null && $this->tfields !== null) {
            $this->_tfields_array = json_decode($this->tfields, true) ?? [];
        }
        return $this->_tfields_array ?? [];
    }

    /** @return array */
    function sfields_array() {
        if ($this->_sfields_array === null && $this->sfields !== null) {
            $this->_sfields_array = json_decode($this->sfields, true) ?? [];
        }
        return $this->_sfields_array ?? [];
    }

    /** @param string $fid
     * @return mixed */
    function fval($fid) {
        if (str_starts_with($fid, "s") && strlen($fid) === 3) {
            return $this->$fid ?? 0;
        }
        if (str_starts_with($fid, "t")) {
            $tf = $this->tfields_array();
            return $tf[$fid] ?? null;
        }
        $sf = $this->sfields_array();
        return $sf[$fid] ?? null;
    }

    /** @return bool */
    function save(Conf $conf) {
        $now = Conf::$now;
        if ($this->checkId === 0) {
            $this->timeCreated = $now;
            $this->timeModified = $now;
            $result = $conf->qe(
                "INSERT INTO ReviewQualityCheck SET
                    paperId=?, reviewId=?, contactId=?, status=?,
                    timeCreated=?, timeModified=?, timeResolved=?,
                    s01=?, s02=?, s03=?, s04=?, s05=?, s06=?,
                    tfields=?, sfields=?",
                $this->paperId, $this->reviewId, $this->contactId, $this->status,
                $this->timeCreated, $this->timeModified, $this->timeResolved,
                $this->s01, $this->s02, $this->s03,
                $this->s04, $this->s05, $this->s06,
                $this->tfields, $this->sfields
            );
            if ($result->affected_rows > 0) {
                $this->checkId = $result->insert_id;
                return true;
            }
            return false;
        } else {
            $this->timeModified = $now;
            $result = $conf->qe(
                "UPDATE ReviewQualityCheck SET
                    status=?, timeModified=?, timeResolved=?,
                    s01=?, s02=?, s03=?, s04=?, s05=?, s06=?,
                    tfields=?, sfields=?
                WHERE checkId=?",
                $this->status, $this->timeModified, $this->timeResolved,
                $this->s01, $this->s02, $this->s03,
                $this->s04, $this->s05, $this->s06,
                $this->tfields, $this->sfields,
                $this->checkId
            );
            return $result->affected_rows >= 0;
        }
    }

    /** @return bool */
    function delete(Conf $conf) {
        if ($this->checkId === 0) {
            return false;
        }
        $conf->qe("DELETE FROM ReviewQualityComment WHERE checkId=?", $this->checkId);
        $result = $conf->qe("DELETE FROM ReviewQualityCheck WHERE checkId=?", $this->checkId);
        return $result->affected_rows > 0;
    }

    /** @param int $paperId
     * @return list<ReviewQualityCheckInfo> */
    static function fetch_by_paper(Conf $conf, $paperId) {
        $result = $conf->qe("SELECT * FROM ReviewQualityCheck WHERE paperId=? ORDER BY checkId", $paperId);
        $checks = [];
        while (($row = $result->fetch_object())) {
            $checks[] = self::fetch($row, $conf);
        }
        Dbl::free($result);
        return $checks;
    }

    /** @param int $reviewId
     * @return list<ReviewQualityCheckInfo> */
    static function fetch_by_review(Conf $conf, $reviewId) {
        $result = $conf->qe("SELECT * FROM ReviewQualityCheck WHERE reviewId=? ORDER BY checkId", $reviewId);
        $checks = [];
        while (($row = $result->fetch_object())) {
            $checks[] = self::fetch($row, $conf);
        }
        Dbl::free($result);
        return $checks;
    }

    /** @param int $checkId
     * @return ?ReviewQualityCheckInfo */
    static function fetch_by_id(Conf $conf, $checkId) {
        $result = $conf->qe("SELECT * FROM ReviewQualityCheck WHERE checkId=?", $checkId);
        $row = $result->fetch_object();
        Dbl::free($result);
        return $row ? self::fetch($row, $conf) : null;
    }

    /** @param int $contactId
     * @return list<ReviewQualityCheckInfo> */
    static function fetch_by_checker(Conf $conf, $contactId) {
        $result = $conf->qe("SELECT * FROM ReviewQualityCheck WHERE contactId=? ORDER BY paperId, checkId", $contactId);
        $checks = [];
        while (($row = $result->fetch_object())) {
            $checks[] = self::fetch($row, $conf);
        }
        Dbl::free($result);
        return $checks;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $j = [
            "paperId" => $this->paperId,
            "reviewId" => $this->reviewId,
            "checkId" => $this->checkId,
            "contactId" => $this->contactId,
            "status" => $this->status,
            "status_name" => $this->status_name(),
            "timeCreated" => $this->timeCreated,
            "timeModified" => $this->timeModified
        ];
        if ($this->timeResolved !== null) {
            $j["timeResolved"] = $this->timeResolved;
        }
        for ($i = 1; $i <= 6; ++$i) {
            $fid = sprintf("s%02d", $i);
            if ($this->$fid !== 0) {
                $j[$fid] = $this->$fid;
            }
        }
        if ($this->tfields !== null) {
            $j["tfields"] = $this->tfields_array();
        }
        if ($this->sfields !== null) {
            $j["sfields"] = $this->sfields_array();
        }
        return $j;
    }
}
