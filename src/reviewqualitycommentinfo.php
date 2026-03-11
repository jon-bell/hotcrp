<?php
// reviewqualitycommentinfo.php -- HotCRP helper class for review quality check comments
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class ReviewQualityCommentInfo implements JsonSerializable {
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
    public $rqCommentId = 0;
    /** @var int */
    public $contactId = 0;
    /** @var int */
    public $timeModified = 0;
    /** @var ?string */
    public $comment;
    /** @var int */
    public $commentType = 0;
    /** @var int */
    public $replyTo = 0;
    /** @var int */
    public $ordinal = 0;
    /** @var ?string */
    public $commentOverflow;

    const RQCVIS_PRIVATE = 0;
    const RQCVIS_META_REVIEWER = 1;
    const RQCVIS_CHAIR = 2;

    /** @var ?Contact */
    private $_commenter;


    function __construct(?Conf $conf = null) {
        if ($conf) {
            $this->conf = $conf;
        }
    }

    /** @param object $x
     * @return ReviewQualityCommentInfo */
    static function fetch($x, Conf $conf) {
        $c = new ReviewQualityCommentInfo($conf);
        $c->paperId = (int) $x->paperId;
        $c->reviewId = (int) $x->reviewId;
        $c->checkId = (int) $x->checkId;
        $c->rqCommentId = (int) $x->rqCommentId;
        $c->contactId = (int) $x->contactId;
        $c->timeModified = (int) $x->timeModified;
        $c->comment = $x->comment ?? null;
        $c->commentType = (int) $x->commentType;
        $c->replyTo = (int) $x->replyTo;
        $c->ordinal = (int) $x->ordinal;
        $c->commentOverflow = $x->commentOverflow ?? null;
        return $c;
    }

    /** @return string */
    function content() {
        if ($this->commentOverflow !== null) {
            return $this->commentOverflow;
        }
        return $this->comment ?? "";
    }

    /** @return bool */
    function save(Conf $conf) {
        $now = Conf::$now;
        if ($this->rqCommentId === 0) {
            $this->timeModified = $now;
            $next_ordinal = 1;
            $result = $conf->qe(
                "SELECT COALESCE(MAX(ordinal),0) FROM ReviewQualityComment WHERE checkId=?",
                $this->checkId
            );
            if (($row = $result->fetch_row())) {
                $next_ordinal = ((int) $row[0]) + 1;
            }
            Dbl::free($result);
            $this->ordinal = $next_ordinal;

            $result = $conf->qe(
                "INSERT INTO ReviewQualityComment SET
                    paperId=?, reviewId=?, checkId=?, contactId=?,
                    timeModified=?, comment=?, commentType=?,
                    replyTo=?, ordinal=?, commentOverflow=?",
                $this->paperId, $this->reviewId, $this->checkId,
                $this->contactId, $this->timeModified,
                $this->comment, $this->commentType,
                $this->replyTo, $this->ordinal,
                $this->commentOverflow
            );
            if ($result->affected_rows > 0) {
                $this->rqCommentId = $result->insert_id;
                return true;
            }
            return false;
        } else {
            $this->timeModified = $now;
            $result = $conf->qe(
                "UPDATE ReviewQualityComment SET
                    comment=?, commentType=?, timeModified=?,
                    commentOverflow=?
                WHERE rqCommentId=?",
                $this->comment, $this->commentType,
                $this->timeModified, $this->commentOverflow,
                $this->rqCommentId
            );
            return $result->affected_rows >= 0;
        }
    }

    /** @return bool */
    function delete(Conf $conf) {
        if ($this->rqCommentId === 0) {
            return false;
        }
        $result = $conf->qe("DELETE FROM ReviewQualityComment WHERE rqCommentId=?", $this->rqCommentId);
        return $result->affected_rows > 0;
    }

    /** @param int $checkId
     * @return list<ReviewQualityCommentInfo> */
    static function fetch_by_check(Conf $conf, $checkId) {
        $result = $conf->qe(
            "SELECT * FROM ReviewQualityComment WHERE checkId=? ORDER BY ordinal",
            $checkId
        );
        $comments = [];
        while (($row = $result->fetch_object())) {
            $comments[] = self::fetch($row, $conf);
        }
        Dbl::free($result);
        return $comments;
    }

    /** @param int $reviewId
     * @return list<ReviewQualityCommentInfo> */
    static function fetch_by_review(Conf $conf, $reviewId) {
        $result = $conf->qe(
            "SELECT * FROM ReviewQualityComment WHERE reviewId=? ORDER BY checkId, ordinal",
            $reviewId
        );
        $comments = [];
        while (($row = $result->fetch_object())) {
            $comments[] = self::fetch($row, $conf);
        }
        Dbl::free($result);
        return $comments;
    }

    /** @param int $rqCommentId
     * @return ?ReviewQualityCommentInfo */
    static function fetch_by_id(Conf $conf, $rqCommentId) {
        $result = $conf->qe(
            "SELECT * FROM ReviewQualityComment WHERE rqCommentId=?",
            $rqCommentId
        );
        $row = $result->fetch_object();
        Dbl::free($result);
        return $row ? self::fetch($row, $conf) : null;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $j = [
            "paperId" => $this->paperId,
            "reviewId" => $this->reviewId,
            "checkId" => $this->checkId,
            "rqCommentId" => $this->rqCommentId,
            "contactId" => $this->contactId,
            "timeModified" => $this->timeModified,
            "content" => $this->content(),
            "commentType" => $this->commentType,
            "replyTo" => $this->replyTo,
            "ordinal" => $this->ordinal
        ];
        return $j;
    }
}
