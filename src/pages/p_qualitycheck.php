<?php
// pages/p_qualitycheck.php -- HotCRP review quality check pages
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class QualityCheck_Page {
    static function go(Contact $user, Qrequest $qreq) {
        if (!$user->isPC && !$user->privChair) {
            Multiconference::fail(403, ["title" => "Access denied"], "<0>You don't have permission to view quality checks.");
            return;
        }

        $conf = $user->conf;
        $qreq->print_header("Review quality checks", "qualitycheck");
        echo '<div id="quality-check-dashboard">';

        if ($user->privChair) {
            self::print_chair_dashboard($conf, $user);
        } else {
            self::print_meta_reviewer_dashboard($conf, $user);
        }

        echo '</div>';
        $qreq->print_footer();
    }

    static private function print_chair_dashboard(Conf $conf, Contact $user) {
        echo '<h2>Review Quality Check Dashboard</h2>';
        echo '<p>Overview of review quality check activity across all papers.</p>';

        $result = $conf->qe(
            "SELECT rqc.status, COUNT(*) AS cnt FROM ReviewQualityCheck rqc GROUP BY rqc.status"
        );
        $counts = [0 => 0, 1 => 0, 2 => 0];
        while (($row = $result->fetch_object())) {
            $counts[(int) $row->status] = (int) $row->cnt;
        }
        Dbl::free($result);

        echo '<div class="d-flex mb-3">';
        echo '<div class="mr-4"><strong>Pending:</strong> ', $counts[0], '</div>';
        echo '<div class="mr-4"><strong>Needs work:</strong> ', $counts[1], '</div>';
        echo '<div class="mr-4"><strong>Improved:</strong> ', $counts[2], '</div>';
        echo '<div><strong>Total:</strong> ', array_sum($counts), '</div>';
        echo '</div>';

        echo '<h3>Meta-reviewer activity</h3>';
        $result = $conf->qe(
            "SELECT rqc.contactId, ci.firstName, ci.lastName, ci.email,
                    COUNT(*) AS total_checks,
                    SUM(CASE WHEN rqc.status=1 THEN 1 ELSE 0 END) AS needs_work,
                    SUM(CASE WHEN rqc.status=2 THEN 1 ELSE 0 END) AS improved,
                    MAX(rqc.timeModified) AS lastActivity
             FROM ReviewQualityCheck rqc
             JOIN ContactInfo ci ON ci.contactId=rqc.contactId
             GROUP BY rqc.contactId
             ORDER BY lastActivity DESC"
        );
        echo '<table class="pltable"><thead><tr>';
        echo '<th class="pl plh">Meta-reviewer</th>';
        echo '<th class="pl plh plr">Checks</th>';
        echo '<th class="pl plh plr">Needs work</th>';
        echo '<th class="pl plh plr">Improved</th>';
        echo '<th class="pl plh">Last activity</th>';
        echo '</tr></thead><tbody>';
        $any = false;
        while (($row = $result->fetch_object())) {
            $any = true;
            $name = htmlspecialchars(trim(($row->firstName ?? "") . " " . ($row->lastName ?? "")));
            $email = htmlspecialchars($row->email ?? "");
            echo '<tr>';
            echo '<td class="pl">', $name, ' &lt;', $email, '&gt;</td>';
            echo '<td class="pl plr">', (int) $row->total_checks, '</td>';
            echo '<td class="pl plr">', (int) $row->needs_work, '</td>';
            echo '<td class="pl plr">', (int) $row->improved, '</td>';
            echo '<td class="pl">', $conf->unparse_time((int) $row->lastActivity), '</td>';
            echo '</tr>';
        }
        if (!$any) {
            echo '<tr><td class="pl" colspan="5">No quality checks yet.</td></tr>';
        }
        Dbl::free($result);
        echo '</tbody></table>';

        echo '<h3>Reviewer response status</h3>';
        $result = $conf->qe(
            "SELECT rqc.checkId, rqc.paperId, rqc.reviewId, rqc.status, rqc.contactId AS checkerId,
                    checker.firstName AS checkerFirst, checker.lastName AS checkerLast,
                    pr.contactId AS reviewerId,
                    reviewer.firstName AS reviewerFirst, reviewer.lastName AS reviewerLast,
                    (SELECT COUNT(*) FROM ReviewQualityComment rqcmt
                     WHERE rqcmt.checkId=rqc.checkId AND rqcmt.contactId=pr.contactId) AS reviewerComments,
                    (SELECT MAX(rqcmt.timeModified) FROM ReviewQualityComment rqcmt
                     WHERE rqcmt.checkId=rqc.checkId AND rqcmt.contactId=pr.contactId) AS lastReviewerResponse
             FROM ReviewQualityCheck rqc
             JOIN PaperReview pr ON pr.reviewId=rqc.reviewId
             JOIN ContactInfo checker ON checker.contactId=rqc.contactId
             JOIN ContactInfo reviewer ON reviewer.contactId=pr.contactId
             WHERE rqc.status=1
             ORDER BY rqc.timeModified DESC
             LIMIT 100"
        );
        echo '<table class="pltable"><thead><tr>';
        echo '<th class="pl plh">Paper</th>';
        echo '<th class="pl plh">Reviewer</th>';
        echo '<th class="pl plh">Checker</th>';
        echo '<th class="pl plh">Status</th>';
        echo '<th class="pl plh plr">Reviewer responses</th>';
        echo '<th class="pl plh">Last response</th>';
        echo '</tr></thead><tbody>';
        $any2 = false;
        while (($row = $result->fetch_object())) {
            $any2 = true;
            $reviewer_name = htmlspecialchars(trim(($row->reviewerFirst ?? "") . " " . ($row->reviewerLast ?? "")));
            $checker_name = htmlspecialchars(trim(($row->checkerFirst ?? "") . " " . ($row->checkerLast ?? "")));
            $status = ReviewQualityCheckInfo::$status_names[(int) $row->status] ?? "Unknown";
            $responses = (int) ($row->reviewerComments ?? 0);
            $lastResponse = $row->lastReviewerResponse ? $conf->unparse_time((int) $row->lastReviewerResponse) : "—";

            echo '<tr>';
            echo '<td class="pl"><a href="', $conf->hoturl("paper", ["p" => $row->paperId]), '">#', $row->paperId, '</a></td>';
            echo '<td class="pl">', $reviewer_name, '</td>';
            echo '<td class="pl">', $checker_name, '</td>';
            echo '<td class="pl"><span class="badge badge-warning">', htmlspecialchars($status), '</span></td>';
            echo '<td class="pl plr">', $responses > 0 ? $responses : '<span class="dim">none</span>', '</td>';
            echo '<td class="pl">', $lastResponse, '</td>';
            echo '</tr>';
        }
        if (!$any2) {
            echo '<tr><td class="pl" colspan="6">No reviews currently need work.</td></tr>';
        }
        Dbl::free($result);
        echo '</tbody></table>';
    }

    static private function print_meta_reviewer_dashboard(Conf $conf, Contact $user) {
        echo '<h2>Your Quality Checks</h2>';

        $checks = ReviewQualityCheckInfo::fetch_by_checker($conf, $user->contactId);
        if (empty($checks)) {
            echo '<p>You have no assigned quality checks.</p>';
            return;
        }

        echo '<table class="pltable"><thead><tr>';
        echo '<th class="pl plh">Paper</th>';
        echo '<th class="pl plh">Review</th>';
        echo '<th class="pl plh">Status</th>';
        echo '<th class="pl plh">Modified</th>';
        echo '<th class="pl plh">Actions</th>';
        echo '</tr></thead><tbody>';
        foreach ($checks as $rqc) {
            $status = $rqc->status_name();
            $status_class = "";
            if ($rqc->is_needs_work()) {
                $status_class = ' class="badge badge-warning"';
            } else if ($rqc->is_improved()) {
                $status_class = ' class="badge badge-success"';
            }
            echo '<tr>';
            echo '<td class="pl"><a href="', $conf->hoturl("paper", ["p" => $rqc->paperId]), '">#', $rqc->paperId, '</a></td>';
            echo '<td class="pl">Review #', $rqc->reviewId, '</td>';
            echo '<td class="pl"><span', $status_class, '>', htmlspecialchars($status), '</span></td>';
            echo '<td class="pl">', $conf->unparse_time($rqc->timeModified), '</td>';
            echo '<td class="pl"><a href="', $conf->hoturl("paper", ["p" => $rqc->paperId, "anchor" => "qualitycheck-{$rqc->checkId}"]), '">View</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    static function print_paper_quality_checks(PaperInfo $prow, Contact $user) {
        $conf = $user->conf;
        if (!$conf->setting("review_quality_check_active")) {
            return;
        }

        $checks = ReviewQualityCheckInfo::fetch_by_paper($conf, $prow->paperId);

        $can_view = false;
        if ($user->privChair) {
            $can_view = true;
        } else {
            foreach ($prow->reviews_as_display() as $rrow) {
                if ($rrow->contactId === $user->contactId) {
                    if ($rrow->reviewType === REVIEW_META) {
                        $can_view = true;
                    } else {
                        foreach ($checks as $rqc) {
                            if ($rqc->reviewId === $rrow->reviewId) {
                                $can_view = true;
                                break 2;
                            }
                        }
                    }
                    break;
                }
            }
        }

        if (!$can_view || empty($checks)) {
            return;
        }

        echo '<div class="revcard" id="quality-checks">';
        echo '<div class="revcard-head"><h2>Review Quality Checks</h2></div>';
        echo '<div class="revcard-body">';

        $qcform = $conf->review_quality_form();
        foreach ($checks as $rqc) {
            $can_view_this = false;
            if ($user->privChair || $rqc->contactId === $user->contactId) {
                $can_view_this = true;
            } else {
                foreach ($prow->reviews_as_display() as $rrow) {
                    if ($rrow->reviewId === $rqc->reviewId && $rrow->contactId === $user->contactId) {
                        $can_view_this = true;
                        break;
                    }
                }
            }
            if (!$can_view_this) {
                continue;
            }

            self::print_single_quality_check($conf, $prow, $rqc, $qcform, $user);
        }

        echo '</div></div>';
    }

    static private function print_single_quality_check(Conf $conf, PaperInfo $prow, ReviewQualityCheckInfo $rqc, ReviewQualityForm $qcform, Contact $user) {
        $status_class = "rqc-pending";
        $status_label = $rqc->status_name();
        if ($rqc->is_needs_work()) {
            $status_class = "rqc-needswork";
        } else if ($rqc->is_improved()) {
            $status_class = "rqc-improved";
        }

        $checker = $conf->user_by_id($rqc->contactId);
        $checker_name = $checker ? $user->name_html_for($checker) : "Unknown";
        $is_checker = $rqc->contactId === $user->contactId;
        $is_reviewee = false;
        foreach ($prow->reviews_as_display() as $rrow) {
            if ($rrow->reviewId === $rqc->reviewId && $rrow->contactId === $user->contactId) {
                $is_reviewee = true;
                break;
            }
        }

        echo '<div class="rqc-card ', $status_class, '" id="qualitycheck-', $rqc->checkId, '">';
        echo '<div class="rqc-header">';
        echo '<span class="rqc-title">Quality check for Review #', $rqc->reviewId, '</span>';
        echo ' <span class="rqc-status badge">', htmlspecialchars($status_label), '</span>';
        echo ' <span class="rqc-meta">by ', $checker_name, '</span>';
        echo ' <span class="rqc-time">', $conf->unparse_time($rqc->timeModified), '</span>';
        echo '</div>';

        echo '<div class="rqc-fields">';
        foreach ($qcform->all_fields() as $f) {
            $fval = $rqc->fval($f->short_id);
            if ($fval === null || $fval === 0 || $fval === "") {
                continue;
            }
            echo '<div class="rqc-field">';
            echo '<strong>', htmlspecialchars($f->name), ':</strong> ';
            if ($f->is_sfield) {
                if ($f instanceof DiscreteValues_ReviewField) {
                    echo htmlspecialchars($f->unparse_value((int) $fval) ?? (string) $fval);
                } else {
                    echo (int) $fval;
                }
            } else {
                echo '<div class="format0">', htmlspecialchars((string) $fval), '</div>';
            }
            echo '</div>';
        }
        echo '</div>';

        $comments = ReviewQualityCommentInfo::fetch_by_check($conf, $rqc->checkId);
        if (!empty($comments)) {
            echo '<div class="rqc-comments">';
            echo '<h4>Discussion</h4>';
            foreach ($comments as $cmt) {
                $commenter = $conf->user_by_id($cmt->contactId);
                $commenter_name = $commenter ? $user->name_html_for($commenter) : "Unknown";
                $is_reply_by_reviewer = false;
                foreach ($prow->reviews_as_display() as $rrow) {
                    if ($rrow->reviewId === $rqc->reviewId && $rrow->contactId === $cmt->contactId) {
                        $is_reply_by_reviewer = true;
                        break;
                    }
                }
                $role_label = $cmt->contactId === $rqc->contactId ? "Meta-reviewer" : ($is_reply_by_reviewer ? "Reviewer" : "");
                echo '<div class="rqc-comment">';
                echo '<div class="rqc-comment-header">';
                echo '<strong>', $commenter_name, '</strong>';
                if ($role_label) {
                    echo ' <span class="rqc-role">(', htmlspecialchars($role_label), ')</span>';
                }
                echo ' <span class="rqc-time">', $conf->unparse_time($cmt->timeModified), '</span>';
                echo '</div>';
                echo '<div class="rqc-comment-body format0">', htmlspecialchars($cmt->content()), '</div>';
                echo '</div>';
            }
            echo '</div>';
        }

        if ($is_checker || $is_reviewee || $user->privChair) {
            echo '<div class="rqc-actions">';
            echo '<form method="post" action="', $conf->hoturl("api/qualitycheck", ["p" => $prow->paperId]), '">';
            echo Ht::hidden("check_id", $rqc->checkId);
            echo Ht::hidden("action", "comment");

            echo '<div class="f-i">';
            echo '<textarea name="text" class="w-text need-autogrow" rows="2" placeholder="Add a comment..."></textarea>';
            echo '</div>';
            echo '<div class="aab">';
            echo '<div class="aabr">', Ht::submit("submit_comment", "Comment", ["class" => "btn btn-primary"]), '</div>';

            if ($is_checker && $rqc->is_needs_work()) {
                echo '<div class="aabr">',
                     Ht::button("Mark improved", ["class" => "btn btn-success ui js-rqc-resolve", "data-check-id" => $rqc->checkId]),
                     '</div>';
            }
            echo '</div>';
            echo '</form>';
            echo '</div>';
        }

        echo '</div>';
    }
}
