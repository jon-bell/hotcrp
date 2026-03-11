<?php
// api_qualitycheck.php -- HotCRP review quality check API
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class QualityCheck_API {
    /** @return bool */
    static private function can_view_quality_check(Contact $user, PaperInfo $prow, ?ReviewQualityCheckInfo $rqc = null) {
        if ($user->privChair) {
            return true;
        }
        if ($rqc && $rqc->contactId === $user->contactId) {
            return true;
        }
        $is_meta = false;
        foreach ($prow->reviews_as_display() as $rrow) {
            if ($rrow->contactId === $user->contactId
                && $rrow->reviewType === REVIEW_META) {
                $is_meta = true;
                break;
            }
        }
        if ($is_meta) {
            return true;
        }
        if ($rqc) {
            foreach ($prow->reviews_as_display() as $rrow) {
                if ($rrow->reviewId === $rqc->reviewId
                    && $rrow->contactId === $user->contactId) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return bool */
    static private function can_edit_quality_check(Contact $user, PaperInfo $prow, ?ReviewQualityCheckInfo $rqc = null) {
        if ($user->privChair) {
            return true;
        }
        if ($rqc && $rqc->contactId === $user->contactId) {
            return true;
        }
        foreach ($prow->reviews_as_display() as $rrow) {
            if ($rrow->contactId === $user->contactId
                && $rrow->reviewType === REVIEW_META) {
                return true;
            }
        }
        return false;
    }

    /** @return bool */
    static private function can_resolve_quality_check(Contact $user, PaperInfo $prow, ReviewQualityCheckInfo $rqc) {
        if ($user->privChair) {
            return true;
        }
        if ($rqc->contactId === $user->contactId) {
            return true;
        }
        return false;
    }

    static function run(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        if (!$prow) {
            return JsonResult::make_error(404, "<0>Paper not found");
        }
        if (!self::can_view_quality_check($user, $prow)) {
            return JsonResult::make_permission_error();
        }
        if ($qreq->is_post()) {
            return self::run_post($user, $qreq, $prow);
        } else {
            return self::run_get($user, $qreq, $prow);
        }
    }

    static private function run_get(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $reviewId = $qreq->review_id ? (int) $qreq->review_id : null;
        $checkId = $qreq->check_id ? (int) $qreq->check_id : null;

        if ($checkId) {
            $rqc = ReviewQualityCheckInfo::fetch_by_id($user->conf, $checkId);
            if (!$rqc || $rqc->paperId !== $prow->paperId) {
                return JsonResult::make_error(404, "<0>Quality check not found");
            }
            if (!self::can_view_quality_check($user, $prow, $rqc)) {
                return JsonResult::make_permission_error();
            }
            $comments = ReviewQualityCommentInfo::fetch_by_check($user->conf, $checkId);
            return new JsonResult(["ok" => true, "quality_check" => $rqc, "comments" => $comments]);
        }

        if ($reviewId) {
            $checks = ReviewQualityCheckInfo::fetch_by_review($user->conf, $reviewId);
        } else {
            $checks = ReviewQualityCheckInfo::fetch_by_paper($user->conf, $prow->paperId);
        }

        $visible = [];
        foreach ($checks as $rqc) {
            if (self::can_view_quality_check($user, $prow, $rqc)) {
                $visible[] = $rqc;
            }
        }
        return new JsonResult(["ok" => true, "quality_checks" => $visible]);
    }

    static private function run_post(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $action = $qreq->action ?? "save";

        if ($action === "save") {
            return self::save_check($user, $qreq, $prow);
        } else if ($action === "resolve") {
            return self::resolve_check($user, $qreq, $prow);
        } else if ($action === "comment") {
            return self::save_comment($user, $qreq, $prow);
        } else if ($action === "delete") {
            return self::delete_check($user, $qreq, $prow);
        } else if ($action === "delete_comment") {
            return self::delete_comment($user, $qreq, $prow);
        }
        return JsonResult::make_error(400, "<0>Unknown action");
    }

    static private function save_check(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $reviewId = (int) ($qreq->review_id ?? 0);
        $checkId = (int) ($qreq->check_id ?? 0);

        if ($reviewId <= 0) {
            return JsonResult::make_error(400, "<0>Review ID required");
        }

        $rrow = null;
        foreach ($prow->reviews_as_display() as $r) {
            if ($r->reviewId === $reviewId) {
                $rrow = $r;
                break;
            }
        }
        if (!$rrow) {
            return JsonResult::make_error(404, "<0>Review not found");
        }

        if ($checkId > 0) {
            $rqc = ReviewQualityCheckInfo::fetch_by_id($user->conf, $checkId);
            if (!$rqc || $rqc->paperId !== $prow->paperId) {
                return JsonResult::make_error(404, "<0>Quality check not found");
            }
            if (!self::can_edit_quality_check($user, $prow, $rqc)) {
                return JsonResult::make_permission_error();
            }
        } else {
            if (!self::can_edit_quality_check($user, $prow)) {
                return JsonResult::make_permission_error();
            }
            $rqc = ReviewQualityCheckInfo::make_blank($user->conf);
            $rqc->paperId = $prow->paperId;
            $rqc->reviewId = $reviewId;
            $rqc->contactId = $user->contactId;
        }

        $status_str = $qreq->status ?? null;
        if ($status_str !== null) {
            $status_str = strtolower(str_replace(" ", "_", $status_str));
            if (isset(ReviewQualityCheckInfo::$status_map[$status_str])) {
                $rqc->status = ReviewQualityCheckInfo::$status_map[$status_str];
            }
        }

        if ($rqc->status === ReviewQualityCheckInfo::STATUS_IMPROVED && $rqc->timeResolved === null) {
            $rqc->timeResolved = Conf::$now;
        }

        $qcform = $user->conf->review_quality_form();
        foreach ($qcform->all_fields() as $f) {
            $val = $qreq["rqc_{$f->short_id}"] ?? null;
            if ($val !== null) {
                if ($f->is_sfield) {
                    $rqc->{$f->short_id} = (int) $val;
                } else {
                    $tfa = $rqc->tfields_array();
                    $tfa[$f->short_id] = $val;
                    $rqc->tfields = empty($tfa) ? null : json_encode($tfa);
                }
            }
        }

        if ($rqc->save($user->conf)) {
            $comments = ReviewQualityCommentInfo::fetch_by_check($user->conf, $rqc->checkId);
            return new JsonResult(["ok" => true, "quality_check" => $rqc, "comments" => $comments]);
        }
        return JsonResult::make_error(500, "<0>Failed to save quality check");
    }

    static private function resolve_check(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $checkId = (int) ($qreq->check_id ?? 0);
        if ($checkId <= 0) {
            return JsonResult::make_error(400, "<0>Check ID required");
        }

        $rqc = ReviewQualityCheckInfo::fetch_by_id($user->conf, $checkId);
        if (!$rqc || $rqc->paperId !== $prow->paperId) {
            return JsonResult::make_error(404, "<0>Quality check not found");
        }
        if (!self::can_resolve_quality_check($user, $prow, $rqc)) {
            return JsonResult::make_permission_error();
        }

        $rqc->status = ReviewQualityCheckInfo::STATUS_IMPROVED;
        $rqc->timeResolved = Conf::$now;
        if ($rqc->save($user->conf)) {
            return new JsonResult(["ok" => true, "quality_check" => $rqc]);
        }
        return JsonResult::make_error(500, "<0>Failed to resolve quality check");
    }

    static private function save_comment(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $checkId = (int) ($qreq->check_id ?? 0);
        if ($checkId <= 0) {
            return JsonResult::make_error(400, "<0>Check ID required");
        }

        $rqc = ReviewQualityCheckInfo::fetch_by_id($user->conf, $checkId);
        if (!$rqc || $rqc->paperId !== $prow->paperId) {
            return JsonResult::make_error(404, "<0>Quality check not found");
        }
        if (!self::can_view_quality_check($user, $prow, $rqc)) {
            return JsonResult::make_permission_error();
        }

        $is_reviewer = false;
        foreach ($prow->reviews_as_display() as $rrow) {
            if ($rrow->reviewId === $rqc->reviewId && $rrow->contactId === $user->contactId) {
                $is_reviewer = true;
                break;
            }
        }
        $is_meta = $rqc->contactId === $user->contactId;
        if (!$user->privChair && !$is_reviewer && !$is_meta) {
            return JsonResult::make_permission_error();
        }

        $text = trim($qreq->text ?? "");
        if ($text === "") {
            return JsonResult::make_error(400, "<0>Comment text required");
        }

        $rqCommentId = (int) ($qreq->rq_comment_id ?? 0);
        if ($rqCommentId > 0) {
            $cmt = ReviewQualityCommentInfo::fetch_by_id($user->conf, $rqCommentId);
            if (!$cmt || $cmt->checkId !== $checkId) {
                return JsonResult::make_error(404, "<0>Comment not found");
            }
            if ($cmt->contactId !== $user->contactId && !$user->privChair) {
                return JsonResult::make_permission_error();
            }
        } else {
            $cmt = new ReviewQualityCommentInfo($user->conf);
            $cmt->paperId = $prow->paperId;
            $cmt->reviewId = $rqc->reviewId;
            $cmt->checkId = $checkId;
            $cmt->contactId = $user->contactId;
        }

        if (strlen($text) > 32000) {
            $cmt->comment = substr($text, 0, 200);
            $cmt->commentOverflow = $text;
        } else {
            $cmt->comment = $text;
            $cmt->commentOverflow = null;
        }

        $replyTo = (int) ($qreq->reply_to ?? 0);
        if ($replyTo > 0) {
            $cmt->replyTo = $replyTo;
        }

        if ($cmt->save($user->conf)) {
            $all_comments = ReviewQualityCommentInfo::fetch_by_check($user->conf, $checkId);
            return new JsonResult(["ok" => true, "comment" => $cmt, "comments" => $all_comments]);
        }
        return JsonResult::make_error(500, "<0>Failed to save comment");
    }

    static private function delete_check(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $checkId = (int) ($qreq->check_id ?? 0);
        if ($checkId <= 0) {
            return JsonResult::make_error(400, "<0>Check ID required");
        }

        $rqc = ReviewQualityCheckInfo::fetch_by_id($user->conf, $checkId);
        if (!$rqc || $rqc->paperId !== $prow->paperId) {
            return JsonResult::make_error(404, "<0>Quality check not found");
        }
        if (!$user->privChair && $rqc->contactId !== $user->contactId) {
            return JsonResult::make_permission_error();
        }

        if ($rqc->delete($user->conf)) {
            return new JsonResult(["ok" => true]);
        }
        return JsonResult::make_error(500, "<0>Failed to delete quality check");
    }

    static private function delete_comment(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $rqCommentId = (int) ($qreq->rq_comment_id ?? 0);
        if ($rqCommentId <= 0) {
            return JsonResult::make_error(400, "<0>Comment ID required");
        }

        $cmt = ReviewQualityCommentInfo::fetch_by_id($user->conf, $rqCommentId);
        if (!$cmt || $cmt->paperId !== $prow->paperId) {
            return JsonResult::make_error(404, "<0>Comment not found");
        }
        if (!$user->privChair && $cmt->contactId !== $user->contactId) {
            return JsonResult::make_permission_error();
        }

        if ($cmt->delete($user->conf)) {
            return new JsonResult(["ok" => true]);
        }
        return JsonResult::make_error(500, "<0>Failed to delete comment");
    }

    static function dashboard(Contact $user, Qrequest $qreq) {
        if (!$user->privChair && !$user->isPC) {
            return JsonResult::make_permission_error();
        }

        $result = $user->conf->qe(
            "SELECT rqc.*, ci.firstName, ci.lastName, ci.email,
                    (SELECT COUNT(*) FROM ReviewQualityComment rqcmt WHERE rqcmt.checkId=rqc.checkId) AS commentCount
             FROM ReviewQualityCheck rqc
             JOIN ContactInfo ci ON ci.contactId=rqc.contactId
             ORDER BY rqc.timeModified DESC
             LIMIT 200"
        );
        $checks = [];
        while (($row = $result->fetch_object())) {
            $check = ReviewQualityCheckInfo::fetch($row, $user->conf);
            $entry = $check->jsonSerialize();
            $entry["checkerName"] = trim(($row->firstName ?? "") . " " . ($row->lastName ?? ""));
            $entry["checkerEmail"] = $row->email ?? "";
            $entry["commentCount"] = (int) ($row->commentCount ?? 0);
            $checks[] = $entry;
        }
        Dbl::free($result);

        $reviewer_responses = [];
        $result2 = $user->conf->qe(
            "SELECT rqcmt.checkId, rqcmt.contactId, ci.firstName, ci.lastName, ci.email,
                    MAX(rqcmt.timeModified) AS lastResponse
             FROM ReviewQualityComment rqcmt
             JOIN ReviewQualityCheck rqc ON rqc.checkId=rqcmt.checkId
             JOIN ContactInfo ci ON ci.contactId=rqcmt.contactId
             WHERE rqcmt.contactId != rqc.contactId
             GROUP BY rqcmt.checkId, rqcmt.contactId
             ORDER BY lastResponse DESC
             LIMIT 200"
        );
        while (($row2 = $result2->fetch_object())) {
            $reviewer_responses[] = [
                "checkId" => (int) $row2->checkId,
                "contactId" => (int) $row2->contactId,
                "name" => trim(($row2->firstName ?? "") . " " . ($row2->lastName ?? "")),
                "email" => $row2->email,
                "lastResponse" => (int) $row2->lastResponse
            ];
        }
        Dbl::free($result2);

        $pending_count = 0;
        $needs_work_count = 0;
        $improved_count = 0;
        $result3 = $user->conf->qe(
            "SELECT status, COUNT(*) AS cnt FROM ReviewQualityCheck GROUP BY status"
        );
        while (($row3 = $result3->fetch_object())) {
            $s = (int) $row3->status;
            $c = (int) $row3->cnt;
            if ($s === ReviewQualityCheckInfo::STATUS_PENDING) {
                $pending_count = $c;
            } else if ($s === ReviewQualityCheckInfo::STATUS_NEEDS_WORK) {
                $needs_work_count = $c;
            } else if ($s === ReviewQualityCheckInfo::STATUS_IMPROVED) {
                $improved_count = $c;
            }
        }
        Dbl::free($result3);

        return new JsonResult([
            "ok" => true,
            "quality_checks" => $checks,
            "reviewer_responses" => $reviewer_responses,
            "summary" => [
                "pending" => $pending_count,
                "needs_work" => $needs_work_count,
                "improved" => $improved_count
            ]
        ]);
    }
}
