<?php
// api_reviewquality.php -- HotCRP review quality check API
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ReviewQuality_API {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $user;
    /** @var PaperInfo */
    private $prow;
    /** @var MessageSet */
    private $ms;

    function __construct(Contact $user, PaperInfo $prow) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->prow = $prow;
        $this->ms = new MessageSet;
    }

    /** @return JsonResult */
    private function run_list(Qrequest $qreq) {
        if (!$this->user->can_view_review_quality_checks($this->prow)) {
            return JsonResult::make_error(403, "<0>Permission denied");
        }
        $checks = $this->prow->viewable_review_quality_checks($this->user);
        $result = [];
        foreach ($checks as $qc) {
            $result[] = $qc->unparse_json($this->user);
        }
        return new JsonResult(200, ["ok" => true, "quality_checks" => $result]);
    }

    /** @return JsonResult */
    private function run_get(Qrequest $qreq) {
        $qcid = (int) ($qreq->qc ?? 0);
        $qc = $this->prow->review_quality_check_by_id($qcid);
        if (!$qc) {
            return JsonResult::make_error(404, "<0>Quality check not found");
        }
        if (!$this->user->can_view_review_quality_checks($this->prow)) {
            return JsonResult::make_error(403, "<0>Permission denied");
        }
        $jr = new JsonResult(200, ["ok" => true, "quality_check" => $qc->unparse_json($this->user)]);
        $comments = $this->prow->viewable_review_quality_comments($this->user, $qc->reviewId);
        $jcomments = [];
        foreach ($comments as $rc) {
            $jcomments[] = $rc->unparse_json($this->user);
        }
        $jr["comments"] = $jcomments;
        return $jr;
    }

    /** @return JsonResult */
    private function run_create(Qrequest $qreq) {
        $reviewId = (int) ($qreq->r ?? 0);
        if (!$reviewId) {
            return JsonResult::make_error(400, "<0>Review ID required");
        }
        $rrow = $this->prow->review_by_id($reviewId);
        if (!$rrow) {
            return JsonResult::make_error(404, "<0>Review not found");
        }
        if (!$this->user->can_edit_review_quality_check($this->prow)) {
            return JsonResult::make_error(403, "<0>Permission denied");
        }

        $qc = new ReviewQualityCheckInfo($this->prow);
        $qc->reviewId = $reviewId;
        $req = ["status" => $qreq->status ?? "pending"];

        $form_json = $this->conf->review_quality_form_fields();
        foreach ($form_json as $field) {
            $fid = $field->id ?? "";
            if (isset($qreq->$fid)) {
                $req[$fid] = $qreq->$fid;
            }
        }

        if ($qc->save($req, $this->user)) {
            $this->ms->append_item(MessageItem::success("<0>Quality check created"));
            $jr = new JsonResult(200, ["ok" => true, "quality_check" => $qc->unparse_json($this->user)]);
            $jr["message_list"] = $this->ms->message_list();
            return $jr;
        } else {
            return JsonResult::make_error(400, "<0>Error creating quality check");
        }
    }

    /** @return JsonResult */
    private function run_update(Qrequest $qreq) {
        $qcid = (int) ($qreq->qc ?? 0);
        $qc = $this->prow->review_quality_check_by_id($qcid);
        if (!$qc) {
            return JsonResult::make_error(404, "<0>Quality check not found");
        }
        if (!$this->user->can_edit_review_quality_check($this->prow, $qc)) {
            return JsonResult::make_error(403, "<0>Permission denied");
        }

        $req = [];
        if (isset($qreq->status)) {
            $req["status"] = $qreq->status;
        }
        $form_json = $this->conf->review_quality_form_fields();
        foreach ($form_json as $field) {
            $fid = $field->id ?? "";
            if (isset($qreq->$fid)) {
                $req[$fid] = $qreq->$fid;
            }
        }

        if ($qc->save($req, $this->user)) {
            $this->ms->append_item(MessageItem::success("<0>Quality check updated"));
            $jr = new JsonResult(200, ["ok" => true, "quality_check" => $qc->unparse_json($this->user)]);
            $jr["message_list"] = $this->ms->message_list();
            return $jr;
        } else {
            return JsonResult::make_error(400, "<0>Error updating quality check");
        }
    }

    /** @return JsonResult */
    private function run_update_status(Qrequest $qreq) {
        $qcid = (int) ($qreq->qc ?? 0);
        $qc = $this->prow->review_quality_check_by_id($qcid);
        if (!$qc) {
            return JsonResult::make_error(404, "<0>Quality check not found");
        }
        if (!$this->user->can_edit_review_quality_check($this->prow, $qc)) {
            return JsonResult::make_error(403, "<0>Permission denied");
        }
        $status_name = $qreq->status ?? "";
        $status = ReviewQualityCheckInfo::$status_map[$status_name] ?? null;
        if ($status === null) {
            return JsonResult::make_error(400, "<0>Invalid status '{$status_name}'");
        }
        if ($qc->update_status($status)) {
            $this->ms->append_item(MessageItem::success("<0>Status updated to " . str_replace('_', ' ', $status_name)));
            $jr = new JsonResult(200, ["ok" => true, "quality_check" => $qc->unparse_json($this->user)]);
            $jr["message_list"] = $this->ms->message_list();
            return $jr;
        }
        return JsonResult::make_error(400, "<0>Error updating status");
    }

    /** @return JsonResult */
    private function run_comment(Qrequest $qreq) {
        $reviewId = (int) ($qreq->r ?? 0);
        $qcid = (int) ($qreq->qc ?? 0);

        if ($qreq->is_get()) {
            return $this->run_list_comments($reviewId);
        }

        if (!isset($qreq->text) && !isset($qreq->delete)) {
            return JsonResult::make_error(400, "<0>Bad request");
        }

        $rcid = $qreq->rqcid ?? null;
        if ($rcid !== null && $rcid !== "new") {
            $rcid = (int) $rcid;
            $result = $this->conf->qe("select * from ReviewQualityComment where reviewQualityCommentId=? and paperId=?", $rcid, $this->prow->paperId);
            $rc = ReviewQualityCommentInfo::fetch($result, $this->prow, $this->conf);
            Dbl::free($result);
            if (!$rc) {
                return JsonResult::make_error(404, "<0>Comment not found");
            }
            if (!$this->user->can_edit_review_quality_comment($this->prow, $rc)) {
                return JsonResult::make_error(403, "<0>Permission denied");
            }
        } else {
            $rc = ReviewQualityCommentInfo::make_new($this->user, $this->prow, $reviewId, $qcid);
            if (!$this->user->can_edit_review_quality_check($this->prow)) {
                $rrow = $this->prow->review_by_id($reviewId);
                if (!$rrow || $rrow->contactId !== $this->user->contactId) {
                    return JsonResult::make_error(403, "<0>Permission denied");
                }
                $rc->commentType |= ReviewQualityCommentInfo::RQCT_BY_REVIEWER;
            }
        }

        $req = [
            "text" => $qreq->delete ? false : rtrim(cleannl((string) $qreq->text)),
            "visibility" => $qreq->visibility ?? "meta",
            "blind" => $qreq->blind
        ];

        if ($req["text"] === "" && !$qreq->delete) {
            return JsonResult::make_error(400, "<0>Comment text required");
        }

        if ($rc->save_comment($req, $this->user)) {
            $action = ($qreq->delete || $req["text"] === false) ? "deleted" : ($rcid === "new" || $rcid === null ? "saved" : "updated");
            $this->ms->append_item(MessageItem::success("<0>Comment {$action}"));
            $jr = new JsonResult(200, ["ok" => true]);
            if ($rc->reviewQualityCommentId > 0) {
                $jr["comment"] = $rc->unparse_json($this->user);
            }
            $jr["message_list"] = $this->ms->message_list();
            return $jr;
        }
        return JsonResult::make_error(400, "<0>Error saving comment");
    }

    /** @return JsonResult */
    private function run_list_comments($reviewId) {
        if (!$this->user->can_view_review_quality_checks($this->prow)) {
            return JsonResult::make_error(403, "<0>Permission denied");
        }
        $comments = $this->prow->viewable_review_quality_comments($this->user, $reviewId);
        $jcomments = [];
        foreach ($comments as $rc) {
            $jcomments[] = $rc->unparse_json($this->user);
        }
        return new JsonResult(200, ["ok" => true, "comments" => $jcomments]);
    }

    /** @return JsonResult */
    static function run(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $api = new ReviewQuality_API($user, $prow);
        $action = $qreq->rqaction ?? "";

        if ($action === "list") {
            return $api->run_list($qreq);
        } else if ($action === "get") {
            return $api->run_get($qreq);
        } else if ($action === "create" && $qreq->is_post()) {
            return $api->run_create($qreq);
        } else if ($action === "update" && $qreq->is_post()) {
            return $api->run_update($qreq);
        } else if ($action === "status" && $qreq->is_post()) {
            return $api->run_update_status($qreq);
        } else if ($action === "comment") {
            return $api->run_comment($qreq);
        } else {
            return JsonResult::make_error(400, "<0>Unknown action");
        }
    }

    /** @return JsonResult */
    static function dashboard(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        if (!$user->can_view_review_quality_dashboard()) {
            return JsonResult::make_error(403, "<0>Permission denied");
        }
        $conf = $user->conf;

        $result = $conf->qe(
            "select qc.*, pr.contactId as reviewerContactId,
                    (select count(*) from ReviewQualityComment rqc where rqc.reviewQualityCheckId=qc.reviewQualityCheckId) as comment_count,
                    (select count(distinct rqc.contactId) from ReviewQualityComment rqc where rqc.reviewQualityCheckId=qc.reviewQualityCheckId and rqc.contactId != qc.contactId) as responder_count
             from ReviewQualityCheck qc
             join PaperReview pr on pr.reviewId=qc.reviewId
             order by qc.timeModified desc"
        );

        $checks = [];
        while ($result && ($row = $result->fetch_object())) {
            $checker = $conf->user_by_id((int) $row->contactId);
            $reviewer = $conf->user_by_id((int) $row->reviewerContactId);
            $checks[] = [
                "id" => (int) $row->reviewQualityCheckId,
                "pid" => (int) $row->paperId,
                "rid" => (int) $row->reviewId,
                "status" => ReviewQualityCheckInfo::$status_names[(int) $row->status] ?? "unknown",
                "checker" => $checker ? $user->reviewer_html_for($checker) : "Unknown",
                "checker_email" => $checker ? $checker->email : "",
                "reviewer" => $reviewer ? $user->reviewer_html_for($reviewer) : "Unknown",
                "reviewer_email" => $reviewer ? $reviewer->email : "",
                "time_created" => (int) $row->timeCreated,
                "time_modified" => (int) $row->timeModified,
                "comment_count" => (int) $row->comment_count,
                "responder_count" => (int) $row->responder_count,
                "has_response" => (int) $row->responder_count > 0
            ];
        }
        Dbl::free($result);

        $meta_reviewers = [];
        $mresult = $conf->qe(
            "select distinct qc.contactId, count(*) as check_count,
                    sum(qc.status = 0) as pending_count,
                    sum(qc.status = 1) as needs_work_count,
                    sum(qc.status = 2) as improved_count,
                    sum(qc.status = 3) as approved_count
             from ReviewQualityCheck qc
             group by qc.contactId"
        );
        while ($mresult && ($row = $mresult->fetch_object())) {
            $mr_user = $conf->user_by_id((int) $row->contactId);
            $meta_reviewers[] = [
                "user" => $mr_user ? $user->reviewer_html_for($mr_user) : "Unknown",
                "email" => $mr_user ? $mr_user->email : "",
                "check_count" => (int) $row->check_count,
                "pending" => (int) $row->pending_count,
                "needs_work" => (int) $row->needs_work_count,
                "improved" => (int) $row->improved_count,
                "approved" => (int) $row->approved_count
            ];
        }
        Dbl::free($mresult);

        $unresponded = [];
        $uresult = $conf->qe(
            "select qc.reviewQualityCheckId, qc.paperId, qc.reviewId, qc.status, qc.timeModified,
                    pr.contactId as reviewer_cid
             from ReviewQualityCheck qc
             join PaperReview pr on pr.reviewId=qc.reviewId
             where qc.status = 1
             and not exists (
                 select 1 from ReviewQualityComment rqc
                 where rqc.reviewQualityCheckId=qc.reviewQualityCheckId
                 and rqc.contactId=pr.contactId
             )
             order by qc.timeModified asc"
        );
        while ($uresult && ($row = $uresult->fetch_object())) {
            $reviewer = $conf->user_by_id((int) $row->reviewer_cid);
            $unresponded[] = [
                "qcid" => (int) $row->reviewQualityCheckId,
                "pid" => (int) $row->paperId,
                "rid" => (int) $row->reviewId,
                "reviewer" => $reviewer ? $user->reviewer_html_for($reviewer) : "Unknown",
                "time_modified" => (int) $row->timeModified
            ];
        }
        Dbl::free($uresult);

        return new JsonResult(200, [
            "ok" => true,
            "checks" => $checks,
            "meta_reviewers" => $meta_reviewers,
            "unresponded" => $unresponded
        ]);
    }
}
