<?php
// test/t_qualitycheck.php -- HotCRP tests for review quality checks

class QualityCheck_Tester {
    /** @var Conf */
    private $conf;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    function test_quality_check_info() {
        $rqc = ReviewQualityCheckInfo::make_blank($this->conf);
        xassert_eqq($rqc->status, ReviewQualityCheckInfo::STATUS_PENDING);
        xassert_eqq($rqc->status_name(), "Pending");
        xassert($rqc->is_pending());
        xassert(!$rqc->is_needs_work());
        xassert(!$rqc->is_improved());

        $rqc->status = ReviewQualityCheckInfo::STATUS_NEEDS_WORK;
        xassert_eqq($rqc->status_name(), "Needs work");
        xassert($rqc->is_needs_work());

        $rqc->status = ReviewQualityCheckInfo::STATUS_IMPROVED;
        xassert_eqq($rqc->status_name(), "Improved");
        xassert($rqc->is_improved());
    }

    function test_quality_check_crud() {
        $conf = $this->conf;
        $result = $conf->qe("SELECT paperId, reviewId, contactId FROM PaperReview LIMIT 1");
        $row = $result->fetch_object();
        Dbl::free($result);
        if (!$row) {
            return;
        }

        $rqc = ReviewQualityCheckInfo::make_blank($conf);
        $rqc->paperId = (int) $row->paperId;
        $rqc->reviewId = (int) $row->reviewId;
        $rqc->contactId = 1; // chair
        $rqc->status = ReviewQualityCheckInfo::STATUS_NEEDS_WORK;
        $rqc->s01 = 2;
        $rqc->tfields = json_encode(["t01" => "This review could be more detailed"]);

        xassert($rqc->save($conf));
        xassert($rqc->checkId > 0);

        $fetched = ReviewQualityCheckInfo::fetch_by_id($conf, $rqc->checkId);
        xassert(!!$fetched);
        xassert_eqq($fetched->paperId, $rqc->paperId);
        xassert_eqq($fetched->reviewId, $rqc->reviewId);
        xassert_eqq($fetched->contactId, 1);
        xassert_eqq($fetched->status, ReviewQualityCheckInfo::STATUS_NEEDS_WORK);
        xassert_eqq($fetched->s01, 2);
        xassert_eqq($fetched->fval("t01"), "This review could be more detailed");

        $by_paper = ReviewQualityCheckInfo::fetch_by_paper($conf, $rqc->paperId);
        xassert(count($by_paper) >= 1);

        $by_review = ReviewQualityCheckInfo::fetch_by_review($conf, $rqc->reviewId);
        xassert(count($by_review) >= 1);

        $by_checker = ReviewQualityCheckInfo::fetch_by_checker($conf, 1);
        xassert(count($by_checker) >= 1);

        $rqc->status = ReviewQualityCheckInfo::STATUS_IMPROVED;
        $rqc->timeResolved = Conf::$now;
        xassert($rqc->save($conf));

        $fetched2 = ReviewQualityCheckInfo::fetch_by_id($conf, $rqc->checkId);
        xassert_eqq($fetched2->status, ReviewQualityCheckInfo::STATUS_IMPROVED);
        xassert($fetched2->timeResolved > 0);

        xassert($rqc->delete($conf));
        $fetched3 = ReviewQualityCheckInfo::fetch_by_id($conf, $rqc->checkId);
        xassert(!$fetched3);
    }

    function test_quality_comment_crud() {
        $conf = $this->conf;
        $result = $conf->qe("SELECT paperId, reviewId, contactId FROM PaperReview LIMIT 1");
        $row = $result->fetch_object();
        Dbl::free($result);
        if (!$row) {
            return;
        }

        $rqc = ReviewQualityCheckInfo::make_blank($conf);
        $rqc->paperId = (int) $row->paperId;
        $rqc->reviewId = (int) $row->reviewId;
        $rqc->contactId = 1;
        $rqc->status = ReviewQualityCheckInfo::STATUS_NEEDS_WORK;
        xassert($rqc->save($conf));

        $cmt = new ReviewQualityCommentInfo($conf);
        $cmt->paperId = $rqc->paperId;
        $cmt->reviewId = $rqc->reviewId;
        $cmt->checkId = $rqc->checkId;
        $cmt->contactId = 1;
        $cmt->comment = "Please elaborate on your methodology critique.";
        xassert($cmt->save($conf));
        xassert($cmt->rqCommentId > 0);
        xassert_eqq($cmt->ordinal, 1);

        $cmt2 = new ReviewQualityCommentInfo($conf);
        $cmt2->paperId = $rqc->paperId;
        $cmt2->reviewId = $rqc->reviewId;
        $cmt2->checkId = $rqc->checkId;
        $cmt2->contactId = (int) $row->contactId;
        $cmt2->comment = "I have updated my review with more details.";
        $cmt2->replyTo = $cmt->rqCommentId;
        xassert($cmt2->save($conf));
        xassert_eqq($cmt2->ordinal, 2);

        $comments = ReviewQualityCommentInfo::fetch_by_check($conf, $rqc->checkId);
        xassert_eqq(count($comments), 2);
        xassert_eqq($comments[0]->content(), "Please elaborate on your methodology critique.");
        xassert_eqq($comments[1]->content(), "I have updated my review with more details.");
        xassert_eqq($comments[1]->replyTo, $cmt->rqCommentId);

        $by_review = ReviewQualityCommentInfo::fetch_by_review($conf, $rqc->reviewId);
        xassert(count($by_review) >= 2);

        $fetched_cmt = ReviewQualityCommentInfo::fetch_by_id($conf, $cmt->rqCommentId);
        xassert(!!$fetched_cmt);
        xassert_eqq($fetched_cmt->content(), "Please elaborate on your methodology critique.");

        xassert($cmt2->delete($conf));
        xassert($cmt->delete($conf));
        xassert($rqc->delete($conf));
    }

    function test_quality_form() {
        $form = new ReviewQualityForm($this->conf, null);
        $fields = $form->all_fields();
        xassert(count($fields) >= 2);

        $s01 = $form->field("s01");
        xassert(!!$s01);
        xassert_eqq($s01->name, "Review completeness");

        $t01 = $form->field("t01");
        xassert(!!$t01);
        xassert_eqq($t01->name, "Feedback for reviewer");
    }

    function test_quality_check_json() {
        $rqc = ReviewQualityCheckInfo::make_blank($this->conf);
        $rqc->paperId = 1;
        $rqc->reviewId = 25;
        $rqc->checkId = 99;
        $rqc->contactId = 1;
        $rqc->status = ReviewQualityCheckInfo::STATUS_NEEDS_WORK;
        $rqc->s01 = 3;

        $json = $rqc->jsonSerialize();
        xassert_eqq($json["paperId"], 1);
        xassert_eqq($json["reviewId"], 25);
        xassert_eqq($json["status"], 1);
        xassert_eqq($json["status_name"], "Needs work");
        xassert_eqq($json["s01"], 3);
    }

    function test_quality_check_status_map() {
        xassert_eqq(ReviewQualityCheckInfo::$status_map["pending"], ReviewQualityCheckInfo::STATUS_PENDING);
        xassert_eqq(ReviewQualityCheckInfo::$status_map["needs_work"], ReviewQualityCheckInfo::STATUS_NEEDS_WORK);
        xassert_eqq(ReviewQualityCheckInfo::$status_map["improved"], ReviewQualityCheckInfo::STATUS_IMPROVED);
    }

    function test_quality_comment_json() {
        $cmt = new ReviewQualityCommentInfo($this->conf);
        $cmt->paperId = 1;
        $cmt->reviewId = 25;
        $cmt->checkId = 99;
        $cmt->rqCommentId = 5;
        $cmt->contactId = 1;
        $cmt->timeModified = 1700000000;
        $cmt->comment = "Test comment";

        $json = $cmt->jsonSerialize();
        xassert_eqq($json["paperId"], 1);
        xassert_eqq($json["checkId"], 99);
        xassert_eqq($json["content"], "Test comment");
    }
}
