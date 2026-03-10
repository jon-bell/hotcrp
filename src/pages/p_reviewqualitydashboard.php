<?php
// pages/p_reviewqualitydashboard.php -- HotCRP review quality dashboard for chairs
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ReviewQualityDashboard_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Qrequest */
    public $qreq;

    function __construct(Contact $user, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
    }

    private function print_header() {
        $this->qreq->print_header("Review quality dashboard", "reviewqualitydashboard", [
            "title_div" => ""
        ]);
    }

    private function print_summary_stats() {
        $result = $this->conf->qe(
            "select count(*) as total,
                    sum(status = 0) as pending,
                    sum(status = 1) as needs_work,
                    sum(status = 2) as improved,
                    sum(status = 3) as approved
             from ReviewQualityCheck"
        );
        $row = $result->fetch_object();
        Dbl::free($result);

        echo '<div class="revcard">';
        echo '<div class="revcard-head"><h2>Summary</h2></div>';
        echo '<div class="revcard-body">';
        echo '<div class="rqc-stats" style="display:flex;gap:2em;flex-wrap:wrap">';

        $stats = [
            ["Total checks", $row->total ?? 0, ""],
            ["Pending", $row->pending ?? 0, "tag-gray"],
            ["Needs work", $row->needs_work ?? 0, "tag-red"],
            ["Improved", $row->improved ?? 0, "tag-green"],
            ["Approved", $row->approved ?? 0, "tag-blue"]
        ];
        foreach ($stats as $s) {
            echo '<div style="text-align:center">';
            echo '<div style="font-size:2em;font-weight:bold">', (int) $s[1], '</div>';
            if ($s[2]) {
                echo '<span class="badge ', $s[2], '">', $s[0], '</span>';
            } else {
                echo '<span>', $s[0], '</span>';
            }
            echo '</div>';
        }

        echo '</div></div></div>';
    }

    private function print_meta_reviewer_activity() {
        $result = $this->conf->qe(
            "select qc.contactId,
                    count(*) as check_count,
                    sum(qc.status = 0) as pending_count,
                    sum(qc.status = 1) as needs_work_count,
                    sum(qc.status = 2) as improved_count,
                    sum(qc.status = 3) as approved_count,
                    max(qc.timeModified) as last_activity,
                    (select count(distinct rqc.reviewQualityCommentId)
                     from ReviewQualityComment rqc where rqc.contactId=qc.contactId) as comment_count
             from ReviewQualityCheck qc
             group by qc.contactId
             order by check_count desc"
        );

        echo '<div class="revcard">';
        echo '<div class="revcard-head"><h2>Meta Reviewer Activity</h2>';
        echo '<div class="revcard-head-desc">Which meta reviewers have entered feedback</div>';
        echo '</div>';
        echo '<div class="revcard-body">';
        echo '<table class="pltable pltable-fullw">';
        echo '<thead><tr>';
        echo '<th>Meta reviewer</th><th>Checks</th><th>Pending</th><th>Needs work</th>';
        echo '<th>Improved</th><th>Approved</th><th>Comments</th><th>Last activity</th>';
        echo '</tr></thead><tbody>';

        while ($result && ($row = $result->fetch_object())) {
            $mr_user = $this->conf->user_by_id((int) $row->contactId);
            echo '<tr>';
            echo '<td>', $mr_user ? htmlspecialchars($mr_user->name(NAME_E)) : 'Unknown', '</td>';
            echo '<td>', (int) $row->check_count, '</td>';
            echo '<td>', (int) $row->pending_count, '</td>';
            echo '<td>', (int) $row->needs_work_count, '</td>';
            echo '<td>', (int) $row->improved_count, '</td>';
            echo '<td>', (int) $row->approved_count, '</td>';
            echo '<td>', (int) $row->comment_count, '</td>';
            echo '<td>', $this->conf->unparse_time((int) $row->last_activity), '</td>';
            echo '</tr>';
        }
        Dbl::free($result);

        echo '</tbody></table></div></div>';
    }

    private function print_reviewer_responses() {
        $result = $this->conf->qe(
            "select qc.reviewQualityCheckId, qc.paperId, qc.reviewId, qc.status,
                    qc.timeModified as check_time,
                    pr.contactId as reviewer_cid,
                    qc.contactId as checker_cid,
                    (select count(*) from ReviewQualityComment rqc
                     where rqc.reviewQualityCheckId=qc.reviewQualityCheckId
                     and rqc.contactId=pr.contactId) as reviewer_comment_count,
                    (select max(rqc.timeModified) from ReviewQualityComment rqc
                     where rqc.reviewQualityCheckId=qc.reviewQualityCheckId
                     and rqc.contactId=pr.contactId) as reviewer_last_response
             from ReviewQualityCheck qc
             join PaperReview pr on pr.reviewId=qc.reviewId
             where qc.status = 1
             order by qc.timeModified desc"
        );

        echo '<div class="revcard">';
        echo '<div class="revcard-head"><h2>Reviews Needing Work - Response Status</h2>';
        echo '<div class="revcard-head-desc">Which reviewers have responded (or not) to quality feedback</div>';
        echo '</div>';
        echo '<div class="revcard-body">';
        echo '<table class="pltable pltable-fullw">';
        echo '<thead><tr>';
        echo '<th>Paper</th><th>Review</th><th>Reviewer</th><th>Checker</th>';
        echo '<th>Marked</th><th>Reviewer response</th><th></th>';
        echo '</tr></thead><tbody>';

        while ($result && ($row = $result->fetch_object())) {
            $reviewer = $this->conf->user_by_id((int) $row->reviewer_cid);
            $checker = $this->conf->user_by_id((int) $row->checker_cid);
            $has_response = ((int) $row->reviewer_comment_count) > 0;

            echo '<tr class="', $has_response ? '' : 'rqc-no-response', '">';
            echo '<td><a href="', $this->conf->hoturl("paper", ["p" => $row->paperId]), '">#', $row->paperId, '</a></td>';
            echo '<td><a href="', $this->conf->hoturl("reviewquality", ["p" => $row->paperId, "r" => $row->reviewId]), '">',
                'Review #', $row->reviewId, '</a></td>';
            echo '<td>', $reviewer ? htmlspecialchars($reviewer->name(NAME_E)) : 'Unknown', '</td>';
            echo '<td>', $checker ? htmlspecialchars($checker->name(NAME_E)) : 'Unknown', '</td>';
            echo '<td>', $this->conf->unparse_time((int) $row->check_time), '</td>';
            echo '<td>';
            if ($has_response) {
                echo '<span class="badge tag-green">Responded</span>';
                echo ' (', (int) $row->reviewer_comment_count, ' comment(s), last: ',
                    $this->conf->unparse_time((int) $row->reviewer_last_response), ')';
            } else {
                echo '<span class="badge tag-red">No response</span>';
            }
            echo '</td>';
            echo '<td><a href="', $this->conf->hoturl("reviewquality", ["p" => $row->paperId, "r" => $row->reviewId]),
                '">View</a></td>';
            echo '</tr>';
        }
        Dbl::free($result);

        echo '</tbody></table></div></div>';
    }

    private function print_recent_activity() {
        $result = $this->conf->qe(
            "select 'check' as activity_type, qc.paperId, qc.reviewId, qc.contactId,
                    qc.timeModified, qc.status, 0 as is_reviewer_comment
             from ReviewQualityCheck qc
             union all
             select 'comment' as activity_type, rqc.paperId, rqc.reviewId, rqc.contactId,
                    rqc.timeModified, 0 as status,
                    exists(select 1 from PaperReview pr where pr.reviewId=rqc.reviewId and pr.contactId=rqc.contactId) as is_reviewer_comment
             from ReviewQualityComment rqc
             order by timeModified desc
             limit 50"
        );

        echo '<div class="revcard">';
        echo '<div class="revcard-head"><h2>Recent Activity</h2></div>';
        echo '<div class="revcard-body">';
        echo '<table class="pltable pltable-fullw">';
        echo '<thead><tr><th>Time</th><th>Type</th><th>Paper</th><th>User</th><th>Details</th></tr></thead>';
        echo '<tbody>';

        while ($result && ($row = $result->fetch_object())) {
            $u = $this->conf->user_by_id((int) $row->contactId);
            echo '<tr>';
            echo '<td>', $this->conf->unparse_time((int) $row->timeModified), '</td>';
            if ($row->activity_type === "check") {
                $status_name = ReviewQualityCheckInfo::$status_names[(int) $row->status] ?? "unknown";
                echo '<td><span class="badge tag-purple">Quality check</span></td>';
                echo '<td><a href="', $this->conf->hoturl("paper", ["p" => $row->paperId]), '">#', $row->paperId, '</a></td>';
                echo '<td>', $u ? htmlspecialchars($u->name(NAME_E)) : 'Unknown', '</td>';
                echo '<td>Status: <em>', htmlspecialchars(str_replace('_', ' ', $status_name)), '</em></td>';
            } else {
                $badge = ((int) $row->is_reviewer_comment) ? '<span class="badge tag-yellow">Reviewer comment</span>'
                    : '<span class="badge tag-blue">Meta comment</span>';
                echo '<td>', $badge, '</td>';
                echo '<td><a href="', $this->conf->hoturl("paper", ["p" => $row->paperId]), '">#', $row->paperId, '</a></td>';
                echo '<td>', $u ? htmlspecialchars($u->name(NAME_E)) : 'Unknown', '</td>';
                echo '<td><a href="', $this->conf->hoturl("reviewquality", ["p" => $row->paperId, "r" => $row->reviewId]),
                    '">View thread</a></td>';
            }
            echo '</tr>';
        }
        Dbl::free($result);

        echo '</tbody></table></div></div>';
    }

    private function print_assignment_form() {
        echo '<div class="revcard">';
        echo '<div class="revcard-head"><h2>Assign Quality Checks</h2>';
        echo '<div class="revcard-head-desc">Assign review quality checks by paper</div>';
        echo '</div>';
        echo '<div class="revcard-body">';

        echo '<p>Use <a href="', $this->conf->hoturl("bulkassign"), '">bulk assignments</a> with the action <code>reviewqualitycheck</code> to assign quality checks in bulk.</p>';

        echo '<p>Or navigate to a specific paper\'s quality check page:</p>';
        echo Ht::form($this->conf->hoturl("reviewquality"), ["class" => "rqc-assign-form"]);
        echo '<div class="f-i" style="display:flex;gap:0.5em;align-items:center">';
        echo '<label for="rqc-assign-pid">Paper #</label>';
        echo Ht::entry("p", "", ["id" => "rqc-assign-pid", "size" => 8, "class" => "ml"]);
        echo Ht::submit("go", "Go", ["class" => "btn-primary"]);
        echo '</div>';
        echo '</form>';

        echo '</div></div>';
    }

    function print() {
        $this->print_header();

        echo '<div id="settings-main">';
        echo '<h1>Review Quality Dashboard</h1>';

        $this->print_summary_stats();
        $this->print_meta_reviewer_activity();
        $this->print_reviewer_responses();
        $this->print_recent_activity();
        $this->print_assignment_form();

        echo '</div>';
        $this->qreq->print_footer();
    }

    static function go(Contact $user, Qrequest $qreq) {
        if (!$user->can_view_review_quality_dashboard()) {
            Multiconference::fail($qreq, 403, ["title" => "Review quality dashboard"], "<0>Permission denied");
            return;
        }
        if (!$user->conf->setting("review_quality_enabled")) {
            Multiconference::fail($qreq, 403, ["title" => "Review quality dashboard"], "<0>Review quality checks are not enabled. Enable in Settings > Reviews.");
            return;
        }
        $rqdp = new ReviewQualityDashboard_Page($user, $qreq);
        $rqdp->print();
    }
}
