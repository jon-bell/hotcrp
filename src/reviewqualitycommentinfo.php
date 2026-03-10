<?php
// reviewqualitycommentinfo.php -- HotCRP review quality comment objects
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ReviewQualityCommentInfo {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var ?PaperInfo */
    public $prow;

    /** @var int */
    public $reviewQualityCommentId = 0;
    /** @var int */
    public $paperId = 0;
    /** @var int */
    public $reviewId = 0;
    /** @var int */
    public $reviewQualityCheckId = 0;
    /** @var int */
    public $contactId = 0;
    /** @var int */
    public $timeModified = 0;
    /** @var int */
    public $timeNotified = 0;
    /** @var ?string */
    public $comment;
    /** @var ?string */
    public $commentOverflow;
    /** @var int */
    public $commentType = 0;
    /** @var int */
    public $replyTo = 0;
    /** @var int */
    public $ordinal = 0;
    /** @var ?string */
    public $commentTags;
    /** @var ?int */
    public $commentFormat;
    /** @var ?string */
    public $commentData;

    /** @var ?Contact */
    private $_commenter;

    const RQCVIS_ADMINONLY = 0x00000;
    const RQCVIS_META_REVIEWER = 0x10000;
    const RQCVIS_PC = 0x20000;
    const RQCVIS_REVIEWER = 0x30000;
    const RQCVIS_MASK = 0xFFF0000;

    const RQCT_DRAFT = 0x01;
    const RQCT_BLIND = 0x02;
    const RQCT_BY_REVIEWER = 0x04;

    /** @var array<int,string> */
    static private $visibility_map = [
        0x00000 => "admin",
        0x10000 => "meta",
        0x20000 => "pc",
        0x30000 => "rev"
    ];

    /** @var array<string,int> */
    static private $visibility_revmap = [
        "admin" => 0x00000,
        "meta" => 0x10000,
        "pc" => 0x20000,
        "rev" => 0x30000
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
        $this->reviewQualityCommentId = (int) $this->reviewQualityCommentId;
        $this->paperId = (int) $this->paperId;
        $this->reviewId = (int) $this->reviewId;
        $this->reviewQualityCheckId = (int) $this->reviewQualityCheckId;
        $this->contactId = (int) $this->contactId;
        $this->timeModified = (int) $this->timeModified;
        $this->timeNotified = (int) $this->timeNotified;
        $this->commentType = (int) $this->commentType;
        $this->replyTo = (int) $this->replyTo;
        $this->ordinal = (int) $this->ordinal;
        if ($this->commentFormat !== null) {
            $this->commentFormat = (int) $this->commentFormat;
        }
    }

    /** @param Dbl_Result $result
     * @return ?ReviewQualityCommentInfo */
    static function fetch($result, ?PaperInfo $prow, ?Conf $conf) {
        $rc = $result->fetch_object("ReviewQualityCommentInfo", [$prow, $conf]);
        if ($rc) {
            $rc->fetch_incorporate();
        }
        return $rc;
    }

    /** @return ReviewQualityCommentInfo */
    static function make_new(Contact $user, PaperInfo $prow, $reviewId, $qcId = 0) {
        $rc = new ReviewQualityCommentInfo($prow);
        $rc->reviewId = (int) $reviewId;
        $rc->reviewQualityCheckId = (int) $qcId;
        $rc->commentType = self::RQCVIS_META_REVIEWER;
        return $rc;
    }

    /** @return string */
    function raw_contents() {
        return $this->commentOverflow ?? $this->comment ?? "";
    }

    /** @return ?Contact */
    function commenter() {
        if ($this->_commenter === null && $this->contactId > 0) {
            $this->_commenter = $this->conf->user_by_id($this->contactId);
        }
        return $this->_commenter;
    }

    /** @return string */
    function visibility_name() {
        return self::$visibility_map[$this->commentType & self::RQCVIS_MASK] ?? "admin";
    }

    /** @return bool */
    function is_draft() {
        return ($this->commentType & self::RQCT_DRAFT) !== 0;
    }

    /** @return bool */
    function is_by_reviewer() {
        return ($this->commentType & self::RQCT_BY_REVIEWER) !== 0;
    }

    /** @return string */
    function unparse_html_id() {
        return "rqc{$this->reviewQualityCommentId}";
    }

    /** @param array $req
     * @return int */
    function requested_type($req) {
        $ctype = $this->commentType;
        if ($req["blind"] ?? false) {
            $ctype |= self::RQCT_BLIND;
        } else {
            $ctype &= ~self::RQCT_BLIND;
        }
        if (($x = self::$visibility_revmap[$req["visibility"] ?? ""] ?? null) !== null) {
            $ctype = ($ctype & ~self::RQCVIS_MASK) | $x;
        }
        return $ctype;
    }

    /** @param array $req
     * @return bool */
    function save_comment($req, Contact $acting_user) {
        $user = $acting_user;
        if (!$user->contactId) {
            return false;
        }

        $ctype = $this->requested_type($req);
        $text = $req["text"] ?? null;

        if ($text === false) {
            if ($this->reviewQualityCommentId) {
                $this->conf->qe("delete from ReviewQualityComment where reviewQualityCommentId=?", $this->reviewQualityCommentId);
                return true;
            }
            return false;
        }

        $text = (string) $text;

        if (!$this->reviewQualityCommentId) {
            $qa = "contactId, paperId, reviewId, reviewQualityCheckId, commentType, comment, commentOverflow, timeModified";
            if (strlen($text) <= 32000) {
                $result = $this->conf->qe(
                    "insert into ReviewQualityComment ({$qa}) values (?, ?, ?, ?, ?, ?, NULL, ?)",
                    $user->contactId, $this->paperId, $this->reviewId,
                    $this->reviewQualityCheckId, $ctype, $text, Conf::$now
                );
            } else {
                $result = $this->conf->qe(
                    "insert into ReviewQualityComment ({$qa}) values (?, ?, ?, ?, ?, ?, ?, ?)",
                    $user->contactId, $this->paperId, $this->reviewId,
                    $this->reviewQualityCheckId, $ctype,
                    UnicodeHelper::utf8_prefix($text, 200), $text, Conf::$now
                );
            }
            if ($result && $result->affected_rows > 0) {
                $this->reviewQualityCommentId = $result->insert_id;
                $this->contactId = $user->contactId;
                $this->commentType = $ctype;
                $this->comment = strlen($text) <= 32000 ? $text : UnicodeHelper::utf8_prefix($text, 200);
                $this->commentOverflow = strlen($text) > 32000 ? $text : null;
                $this->timeModified = Conf::$now;
                $this->save_ordinal();
                return true;
            }
            return false;
        } else {
            if ($this->timeModified >= Conf::$now) {
                Conf::advance_current_time($this->timeModified);
            }
            if (strlen($text) <= 32000) {
                $result = $this->conf->qe(
                    "update ReviewQualityComment set timeModified=?, commentType=?, comment=?, commentOverflow=NULL where reviewQualityCommentId=?",
                    Conf::$now, $ctype, $text, $this->reviewQualityCommentId
                );
            } else {
                $result = $this->conf->qe(
                    "update ReviewQualityComment set timeModified=?, commentType=?, comment=?, commentOverflow=? where reviewQualityCommentId=?",
                    Conf::$now, $ctype, UnicodeHelper::utf8_prefix($text, 200),
                    $text, $this->reviewQualityCommentId
                );
            }
            if ($result && $result->affected_rows >= 0) {
                $this->commentType = $ctype;
                $this->comment = strlen($text) <= 32000 ? $text : UnicodeHelper::utf8_prefix($text, 200);
                $this->commentOverflow = strlen($text) > 32000 ? $text : null;
                $this->timeModified = Conf::$now;
                return true;
            }
            return false;
        }
    }

    private function save_ordinal() {
        $this->conf->qe(
            "update ReviewQualityComment rqc, (select coalesce(max(ordinal), 0) as maxord from ReviewQualityComment where reviewId=?) t set rqc.ordinal = t.maxord + 1 where rqc.reviewQualityCommentId=?",
            $this->reviewId, $this->reviewQualityCommentId
        );
    }

    /** @return object */
    function unparse_json(Contact $viewer) {
        $j = (object) [
            "rqcid" => $this->reviewQualityCommentId,
            "pid" => $this->paperId,
            "rid" => $this->reviewId,
            "qcid" => $this->reviewQualityCheckId,
            "text" => $this->raw_contents(),
            "visibility" => $this->visibility_name(),
            "is_draft" => $this->is_draft(),
            "is_by_reviewer" => $this->is_by_reviewer(),
            "ordinal" => $this->ordinal,
            "time" => $this->timeModified,
            "html_id" => $this->unparse_html_id()
        ];
        $commenter = $this->commenter();
        if ($commenter && $viewer->can_view_review_quality_commenter_identity($this)) {
            $j->author = $viewer->reviewer_html_for($commenter);
            $j->author_email = $commenter->email;
        }
        if ($this->commentTags) {
            $j->tags = $this->commentTags;
        }
        return $j;
    }
}
