<?php
// t_reviewquality.php -- HotCRP review quality check tests
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ReviewQuality_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact */
    public $u_mgbaker;
    /** @var Contact */
    public $u_estrin;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $this->u_estrin = $conf->checked_user_by_email("estrin@usc.edu");
    }

    function test_schema_tables_exist() {
        $result = $this->conf->qe("SHOW TABLES LIKE 'ReviewQualityCheck'");
        xassert_eqq($result->num_rows, 1);
        Dbl::free($result);

        $result = $this->conf->qe("SHOW TABLES LIKE 'ReviewQualityComment'");
        xassert_eqq($result->num_rows, 1);
        Dbl::free($result);
    }

    function test_enable_feature() {
        $this->conf->save_setting("review_quality_enabled", 1);
        xassert_eqq($this->conf->setting("review_quality_enabled"), 1);
        xassert($this->conf->review_quality_enabled());
    }

    function test_quality_form_storage() {
        $form = [
            (object) [
                "id" => "s01", "name" => "Review quality", "type" => "radio",
                "order" => 1, "description" => "Overall quality of this review",
                "values" => [
                    (object) ["symbol" => "1", "name" => "Very poor"],
                    (object) ["symbol" => "2", "name" => "Poor"],
                    (object) ["symbol" => "3", "name" => "Acceptable"],
                    (object) ["symbol" => "4", "name" => "Good"],
                    (object) ["symbol" => "5", "name" => "Excellent"]
                ]
            ],
            (object) [
                "id" => "t01", "name" => "Detailed feedback", "type" => "text",
                "order" => 2
            ]
        ];
        $this->conf->save_setting("review_quality_form", 1, json_encode($form));

        $fields = $this->conf->review_quality_form_fields();
        xassert_eqq(count($fields), 2);
        xassert_eqq($fields[0]->id, "s01");
        xassert_eqq($fields[0]->name, "Review quality");
        xassert_eqq($fields[1]->id, "t01");
    }

    function test_chair_permissions() {
        $prow = $this->conf->paper_by_id(1, $this->u_chair);
        xassert(!!$prow);

        xassert($this->u_chair->can_view_review_quality_checks($prow));
        xassert($this->u_chair->can_edit_review_quality_check($prow));
        xassert($this->u_chair->can_assign_review_quality_check());
        xassert($this->u_chair->can_view_review_quality_dashboard());
    }

    function test_create_quality_check() {
        $prow = $this->conf->paper_by_id(1, $this->u_chair);
        xassert(!!$prow);

        $reviews = $prow->reviews_as_list();
        if (empty($reviews)) {
            return;
        }
        $rrow = $reviews[0];

        $qc = new ReviewQualityCheckInfo($prow);
        $qc->reviewId = $rrow->reviewId;
        $req = [
            "status" => "needs_work",
            "s01" => 2,
            "t01" => "This review lacks depth and doesn't address the paper's main contributions."
        ];
        $ok = $qc->save($req, $this->u_chair);
        xassert($ok);
        xassert($qc->reviewQualityCheckId > 0);
        xassert_eqq($qc->status, ReviewQualityCheckInfo::STATUS_NEEDS_WORK);
        xassert_eqq($qc->status_name(), "needs_work");

        $fval = $qc->field_value("s01");
        xassert_eqq($fval, 2);
        $fval = $qc->field_value("t01");
        xassert_eqq($fval, "This review lacks depth and doesn't address the paper's main contributions.");
    }

    function test_quality_check_status_transitions() {
        $prow = $this->conf->paper_by_id(1, $this->u_chair);
        xassert(!!$prow);
        $checks = $prow->all_review_quality_checks();
        if (empty($checks)) {
            return;
        }
        $qc = $checks[0];

        xassert($qc->update_status(ReviewQualityCheckInfo::STATUS_IMPROVED));
        xassert_eqq($qc->status, ReviewQualityCheckInfo::STATUS_IMPROVED);
        xassert_eqq($qc->status_name(), "improved");

        xassert($qc->update_status(ReviewQualityCheckInfo::STATUS_APPROVED));
        xassert_eqq($qc->status, ReviewQualityCheckInfo::STATUS_APPROVED);
        xassert_eqq($qc->status_name(), "approved");

        $qc->update_status(ReviewQualityCheckInfo::STATUS_NEEDS_WORK);
    }

    function test_quality_comment_creation() {
        $prow = $this->conf->paper_by_id(1, $this->u_chair);
        xassert(!!$prow);
        $checks = $prow->all_review_quality_checks();
        if (empty($checks)) {
            return;
        }
        $qc = $checks[0];

        $rc = ReviewQualityCommentInfo::make_new($this->u_chair, $prow, $qc->reviewId, $qc->reviewQualityCheckId);
        $req = [
            "text" => "This review needs more specific comments about the methodology section.",
            "visibility" => "meta"
        ];
        $ok = $rc->save_comment($req, $this->u_chair);
        xassert($ok);
        xassert($rc->reviewQualityCommentId > 0);
        xassert_eqq($rc->raw_contents(), "This review needs more specific comments about the methodology section.");
        xassert_eqq($rc->visibility_name(), "meta");
        xassert(!$rc->is_by_reviewer());
    }

    function test_quality_comment_visibility() {
        $prow = $this->conf->paper_by_id(1, $this->u_chair);
        xassert(!!$prow);
        $checks = $prow->all_review_quality_checks();
        if (empty($checks)) {
            return;
        }
        $qc = $checks[0];

        $comments = $prow->review_quality_comments_for_review($qc->reviewId);
        xassert(!empty($comments));

        $rc = $comments[0];
        xassert($this->u_chair->can_view_review_quality_comment($prow, $rc));
    }

    function test_quality_check_json() {
        $prow = $this->conf->paper_by_id(1, $this->u_chair);
        xassert(!!$prow);
        $checks = $prow->all_review_quality_checks();
        if (empty($checks)) {
            return;
        }
        $qc = $checks[0];

        $json = $qc->unparse_json($this->u_chair);
        xassert_eqq($json->pid, $prow->paperId);
        xassert_eqq($json->rid, $qc->reviewId);
        xassert(isset($json->status));
        xassert(isset($json->status_html));
    }

    function test_quality_comment_json() {
        $prow = $this->conf->paper_by_id(1, $this->u_chair);
        xassert(!!$prow);
        $checks = $prow->all_review_quality_checks();
        if (empty($checks)) {
            return;
        }
        $qc = $checks[0];
        $comments = $prow->review_quality_comments_for_review($qc->reviewId);
        if (empty($comments)) {
            return;
        }
        $rc = $comments[0];

        $json = $rc->unparse_json($this->u_chair);
        xassert_eqq($json->pid, $prow->paperId);
        xassert_eqq($json->rid, $qc->reviewId);
        xassert(isset($json->text));
        xassert(isset($json->visibility));
    }

    function test_status_label_html() {
        $prow = $this->conf->paper_by_id(1, $this->u_chair);
        xassert(!!$prow);

        $qc = new ReviewQualityCheckInfo($prow);
        $qc->status = ReviewQualityCheckInfo::STATUS_PENDING;
        xassert_str_contains($qc->status_label_html(), "Pending");
        xassert_str_contains($qc->status_label_html(), "tag-gray");

        $qc->status = ReviewQualityCheckInfo::STATUS_NEEDS_WORK;
        xassert_str_contains($qc->status_label_html(), "Needs work");
        xassert_str_contains($qc->status_label_html(), "tag-red");

        $qc->status = ReviewQualityCheckInfo::STATUS_IMPROVED;
        xassert_str_contains($qc->status_label_html(), "Improved");
        xassert_str_contains($qc->status_label_html(), "tag-green");

        $qc->status = ReviewQualityCheckInfo::STATUS_APPROVED;
        xassert_str_contains($qc->status_label_html(), "Approved");
        xassert_str_contains($qc->status_label_html(), "tag-blue");
    }

    function test_status_map() {
        xassert_eqq(ReviewQualityCheckInfo::$status_map["pending"], 0);
        xassert_eqq(ReviewQualityCheckInfo::$status_map["needs_work"], 1);
        xassert_eqq(ReviewQualityCheckInfo::$status_map["needswork"], 1);
        xassert_eqq(ReviewQualityCheckInfo::$status_map["needs-work"], 1);
        xassert_eqq(ReviewQualityCheckInfo::$status_map["improved"], 2);
        xassert_eqq(ReviewQualityCheckInfo::$status_map["approved"], 3);
    }

    function test_viewable_quality_checks() {
        $prow = $this->conf->paper_by_id(1, $this->u_chair);
        xassert(!!$prow);

        $checks = $prow->viewable_review_quality_checks($this->u_chair);
        xassert(!empty($checks));
    }

    function test_cleanup() {
        $this->conf->qe("DELETE FROM ReviewQualityComment WHERE paperId > 0");
        $this->conf->qe("DELETE FROM ReviewQualityCheck WHERE paperId > 0");
        $this->conf->save_setting("review_quality_enabled", null);
        $this->conf->save_setting("review_quality_form", null);
    }
}
