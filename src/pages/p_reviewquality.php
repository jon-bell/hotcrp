<?php
// pages/p_reviewquality.php -- HotCRP review quality check page
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ReviewQuality_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Qrequest */
    public $qreq;
    /** @var ?PaperInfo */
    public $prow;

    function __construct(Contact $user, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
    }

    private function load_prow() {
        $pr = new PaperRequest($this->user, $this->qreq, true);
        $this->prow = $this->conf->paper_by_id($pr->paperId, $this->user);
        if (!$this->prow) {
            Multiconference::fail($this->qreq, 404, ["title" => "Review quality"], "<0>Submission not found");
            return false;
        }
        return true;
    }

    private function print_header() {
        $this->qreq->print_header("Review quality check", "reviewquality", [
            "title_div" => "",
            "body_class" => "paper",
            "paperId" => $this->prow->paperId
        ]);
    }

    private function print_quality_check_form($qc, $rrow) {
        $form_fields = $this->conf->review_quality_form_fields();
        echo '<div class="revcard rqc-form" id="rqc-', $qc ? $qc->reviewQualityCheckId : 'new', '">';
        echo '<div class="revcard-head">';
        echo '<h2>Review Quality Check';
        if ($qc && $qc->reviewQualityCheckId) {
            echo ' #', $qc->reviewQualityCheckId;
        }
        echo '</h2>';
        if ($qc && $qc->reviewQualityCheckId) {
            echo '<div class="revcard-head-right">', $qc->status_label_html(), '</div>';
        }
        echo '</div>';
        echo '<div class="revcard-body">';

        $editable = $this->user->can_edit_review_quality_check($this->prow, $qc);

        echo '<div class="f-i"><label>Review</label>';
        echo '<div class="f-c">Review #', $rrow->paperId, $rrow->unparse_ordinal_id();
        $reviewer = $this->conf->user_by_id($rrow->contactId);
        if ($reviewer && $this->user->can_view_review_identity($this->prow, $rrow)) {
            echo ' by ', htmlspecialchars($reviewer->name(NAME_E));
        }
        echo '</div></div>';

        if ($editable) {
            echo Ht::form($this->conf->hoturl("api/reviewquality", [
                "p" => $this->prow->paperId,
                "rqaction" => $qc && $qc->reviewQualityCheckId ? "update" : "create",
                "r" => $rrow->reviewId,
                "qc" => $qc ? $qc->reviewQualityCheckId : ""
            ]), ["class" => "rqc-edit-form"]);
            echo Ht::hidden("p", $this->prow->paperId);
        }

        echo '<div class="f-i"><label for="rqc-status">Status</label>';
        if ($editable) {
            $status_options = [
                "pending" => "Pending",
                "needs_work" => "Needs work",
                "improved" => "Improved",
                "approved" => "Approved"
            ];
            $current_status = $qc ? $qc->status_name() : "pending";
            echo Ht::select("status", $status_options, $current_status, ["id" => "rqc-status", "class" => "uich"]);
        } else {
            echo '<div class="f-c">', $qc ? $qc->status_label_html() : '<span class="badge tag-gray">Pending</span>', '</div>';
        }
        echo '</div>';

        foreach ($form_fields as $field) {
            $fid = $field->id ?? "";
            $fname = $field->name ?? $fid;
            $ftype = $field->type ?? "text";
            $fval = $qc ? $qc->field_value($fid) : null;

            echo '<div class="f-i"><label for="rqc-', htmlspecialchars($fid), '">';
            echo htmlspecialchars($fname), '</label>';

            if (!empty($field->description)) {
                echo '<div class="field-d">', $field->description, '</div>';
            }

            if ($editable) {
                if ($ftype === "text") {
                    echo Ht::textarea($fid, $fval ?? "", [
                        "id" => "rqc-" . $fid,
                        "class" => "w-text need-autogrow",
                        "rows" => $field->display_space ?? 3
                    ]);
                } else if ($ftype === "radio" || $ftype === "dropdown") {
                    $values = [];
                    foreach ($field->values ?? [] as $i => $v) {
                        $values[$i + 1] = is_object($v) ? ($v->name ?? $v->symbol ?? ($i + 1)) : $v;
                    }
                    echo Ht::select($fid, $values, $fval, [
                        "id" => "rqc-" . $fid,
                        "class" => "uich"
                    ]);
                }
            } else {
                echo '<div class="f-c">';
                if ($ftype === "text") {
                    echo nl2br(htmlspecialchars($fval ?? ""));
                } else {
                    $values = $field->values ?? [];
                    $idx = ($fval ?? 0) - 1;
                    if ($idx >= 0 && $idx < count($values)) {
                        $v = $values[$idx];
                        echo htmlspecialchars(is_object($v) ? ($v->name ?? $v->symbol ?? "") : $v);
                    } else {
                        echo '<em>Not set</em>';
                    }
                }
                echo '</div>';
            }
            echo '</div>';
        }

        if ($editable) {
            echo '<div class="aab aabig">';
            echo '<div class="aabut">', Ht::submit("save", "Save", ["class" => "btn-primary"]), '</div>';
            echo '</div>';
            echo '</form>';
        }

        echo '</div></div>';
    }

    private function print_quality_comments($reviewId, $qcId = 0) {
        $comments = $this->prow->viewable_review_quality_comments($this->user, $reviewId);

        echo '<div class="revcard rqc-comments" id="rqc-comments-', $reviewId, '">';
        echo '<div class="revcard-head"><h2>Quality Feedback Thread</h2>';
        echo '<div class="revcard-head-desc">Comments on this review\'s quality (private to meta reviewers, chairs, and the reviewer)</div>';
        echo '</div>';
        echo '<div class="revcard-body">';

        if (empty($comments)) {
            echo '<p class="feedback is-note">No quality feedback comments yet.</p>';
        } else {
            echo '<div class="rqc-thread">';
            foreach ($comments as $rc) {
                $this->print_quality_comment($rc);
            }
            echo '</div>';
        }

        $can_comment = $this->user->can_edit_review_quality_check($this->prow)
            || ($this->prow->review_by_id($reviewId)
                && $this->prow->review_by_id($reviewId)->contactId === $this->user->contactId);

        if ($can_comment) {
            echo '<div class="rqc-new-comment">';
            echo '<h3>Add comment</h3>';
            echo Ht::form($this->conf->hoturl("api/reviewquality", [
                "p" => $this->prow->paperId,
                "rqaction" => "comment",
                "r" => $reviewId,
                "qc" => $qcId,
                "rqcid" => "new"
            ]), ["class" => "rqc-comment-form"]);
            echo '<div class="f-i">';
            echo '<label>Visibility</label>';
            $vis_options = ["admin" => "Administrators only", "meta" => "Meta reviewers + reviewer", "pc" => "PC members"];
            echo Ht::select("visibility", $vis_options, "meta", ["class" => "uich rqc-vis-select"]);
            echo '</div>';
            echo '<div class="f-i">';
            echo Ht::textarea("text", "", [
                "class" => "w-text need-autogrow rqc-comment-text",
                "rows" => 3,
                "placeholder" => "Enter quality feedback..."
            ]);
            echo '</div>';
            echo '<div class="aab aabig">';
            echo '<div class="aabut">', Ht::submit("submit", "Add comment", ["class" => "btn-primary"]), '</div>';
            echo '</div>';
            echo '</form>';
            echo '</div>';
        }

        echo '</div></div>';
    }

    private function print_quality_comment(ReviewQualityCommentInfo $rc) {
        $commenter = $rc->commenter();
        $is_reviewer_comment = $rc->is_by_reviewer();

        $cls = "rqc-comment";
        if ($is_reviewer_comment) {
            $cls .= " rqc-comment-reviewer";
        } else {
            $cls .= " rqc-comment-meta";
        }

        echo '<div class="', $cls, '" id="', $rc->unparse_html_id(), '">';
        echo '<div class="rqc-comment-header">';

        if ($commenter && $this->user->can_view_review_quality_commenter_identity($rc)) {
            echo '<span class="rqc-comment-author">', htmlspecialchars($commenter->name(NAME_E)), '</span>';
        } else {
            echo '<span class="rqc-comment-author"><em>Anonymous</em></span>';
        }

        if ($is_reviewer_comment) {
            echo ' <span class="badge tag-yellow">Reviewer</span>';
        } else {
            echo ' <span class="badge tag-purple">Meta reviewer</span>';
        }

        echo ' <span class="rqc-comment-vis badge tag-gray">', htmlspecialchars($rc->visibility_name()), '</span>';
        echo ' <span class="rqc-comment-time">', $this->conf->unparse_time($rc->timeModified), '</span>';

        if ($this->user->can_edit_review_quality_comment($this->prow, $rc)) {
            echo ' <a class="rqc-edit-comment" href="#">edit</a>';
        }

        echo '</div>';
        echo '<div class="rqc-comment-body">', nl2br(htmlspecialchars($rc->raw_contents())), '</div>';
        echo '</div>';
    }

    private function print_review_summary($rrow) {
        echo '<div class="revcard rqc-review-summary">';
        echo '<div class="revcard-head"><h2>Review #', $rrow->paperId, $rrow->unparse_ordinal_id(), '</h2></div>';
        echo '<div class="revcard-body">';

        $reviewer = $this->conf->user_by_id($rrow->contactId);
        if ($reviewer && $this->user->can_view_review_identity($this->prow, $rrow)) {
            echo '<div class="f-i"><label>Reviewer</label><div class="f-c">', htmlspecialchars($reviewer->name(NAME_E)), '</div></div>';
        }

        echo '<div class="f-i"><label>Review type</label><div class="f-c">', htmlspecialchars(ReviewInfo::unparse_type($rrow->reviewType)), '</div></div>';

        $form = $this->conf->review_form();
        foreach ($form->viewable_fields($this->user) as $rf) {
            $fv = $rrow->fval($rf);
            if ($rf->value_present($fv)) {
                echo '<div class="f-i"><label>', htmlspecialchars($rf->name), '</label>';
                echo '<div class="f-c">';
                if ($rf instanceof Discrete_ReviewField) {
                    echo htmlspecialchars($rf->unparse_value($fv));
                } else {
                    echo '<div class="revtext">', nl2br(htmlspecialchars((string) $fv)), '</div>';
                }
                echo '</div></div>';
            }
        }
        echo '</div></div>';
    }

    function print() {
        $this->print_header();

        echo '<div id="paper-main">';
        echo '<div class="paper-desc"><h2>#', $this->prow->paperId, ' ',
            htmlspecialchars($this->prow->title), '</h2></div>';

        $reviewId = (int) ($this->qreq->r ?? 0);
        $qcId = (int) ($this->qreq->qc ?? 0);

        if ($reviewId) {
            $rrow = $this->prow->review_by_id($reviewId);
            if (!$rrow) {
                echo '<p class="feedback is-warning">Review not found.</p>';
            } else if (!$this->user->can_view_review($this->prow, $rrow)) {
                echo '<p class="feedback is-warning">You cannot view this review.</p>';
            } else {
                $this->print_review_summary($rrow);

                $checks = $this->prow->review_quality_checks_for_review($reviewId);
                if (!empty($checks)) {
                    foreach ($checks as $qc) {
                        $this->print_quality_check_form($qc, $rrow);
                    }
                } else if ($this->user->can_edit_review_quality_check($this->prow)) {
                    $this->print_quality_check_form(null, $rrow);
                }

                $this->print_quality_comments($reviewId, $qcId);
            }
        } else {
            $this->print_paper_quality_overview();
        }

        echo '</div>';
        $this->qreq->print_footer();
    }

    private function print_paper_quality_overview() {
        $checks = $this->prow->viewable_review_quality_checks($this->user);

        echo '<div class="revcard">';
        echo '<div class="revcard-head"><h2>Review Quality Checks</h2></div>';
        echo '<div class="revcard-body">';

        if (empty($checks)) {
            echo '<p class="feedback is-note">No quality checks for this submission yet.</p>';
        } else {
            echo '<table class="pltable pltable-fullw">';
            echo '<thead><tr><th>Review</th><th>Checker</th><th>Status</th><th>Modified</th><th></th></tr></thead>';
            echo '<tbody>';
            foreach ($checks as $qc) {
                $rrow = $this->prow->review_by_id($qc->reviewId);
                echo '<tr>';
                echo '<td>';
                if ($rrow) {
                    echo '<a href="', $this->conf->hoturl("reviewquality", ["p" => $this->prow->paperId, "r" => $qc->reviewId]), '">';
                    echo '#', $rrow->paperId, $rrow->unparse_ordinal_id(), '</a>';
                }
                echo '</td>';
                echo '<td>';
                $checker = $qc->checker();
                if ($checker && $this->user->can_view_review_quality_checker_identity($qc)) {
                    echo htmlspecialchars($checker->name(NAME_E));
                } else {
                    echo '<em>Anonymous</em>';
                }
                echo '</td>';
                echo '<td>', $qc->status_label_html(), '</td>';
                echo '<td>', $this->conf->unparse_time($qc->timeModified), '</td>';
                echo '<td><a href="', $this->conf->hoturl("reviewquality", ["p" => $this->prow->paperId, "r" => $qc->reviewId]), '">View</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        if ($this->user->can_edit_review_quality_check($this->prow)) {
            echo '<div class="aab aabig" style="margin-top:1em">';
            echo '<strong>Assign quality check:</strong> ';
            $reviews = $this->prow->reviews_as_display();
            foreach ($reviews as $rrow) {
                if ($this->user->can_view_review($this->prow, $rrow)) {
                    echo '<a class="btn btn-default" style="margin:0.2em" href="',
                        $this->conf->hoturl("reviewquality", ["p" => $this->prow->paperId, "r" => $rrow->reviewId]),
                        '">Review #', $rrow->paperId, $rrow->unparse_ordinal_id(), '</a> ';
                }
            }
            echo '</div>';
        }

        echo '</div></div>';
    }

    static function go(Contact $user, Qrequest $qreq) {
        if (!$user->conf->setting("review_quality_enabled")) {
            Multiconference::fail($qreq, 403, ["title" => "Review quality"], "<0>Review quality checks are not enabled");
            return;
        }
        $rqp = new ReviewQuality_Page($user, $qreq);
        if ($rqp->load_prow()) {
            if (!$user->can_view_review_quality_checks($rqp->prow)) {
                Multiconference::fail($qreq, 403, ["title" => "Review quality"], "<0>Permission denied");
                return;
            }
            $rqp->print();
        }
    }
}
