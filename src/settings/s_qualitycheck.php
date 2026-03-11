<?php
// settings/s_qualitycheck.php -- HotCRP review quality check settings
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class QualityCheck_SettingParser extends SettingParser {
    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name === "review_quality_check_active") {
            $sv->set_oldv($si, $sv->conf->setting("review_quality_check_active") ? 1 : 0);
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "review_quality_check_active") {
            $v = $sv->reqstr($si->name);
            $sv->save($si, $v ? 1 : 0);
            return true;
        }
        return false;
    }

    static function print_main(SettingValues $sv) {
        echo '<div class="settings-g">';
        echo '<h3 class="form-h">Review quality checks</h3>';
        echo '<p>Enable meta-reviewers to conduct quality checks on reviews. ',
             'Meta-reviewers can mark reviews as "needs work" and provide private feedback. ',
             'Reviewers can respond, and meta-reviewers can then mark reviews as "improved".</p>';

        $active = $sv->conf->setting("review_quality_check_active") ? 1 : 0;
        echo Ht::hidden("has_review_quality_check_active", 1);
        echo '<div class="checki"><label><span class="checkc">',
             Ht::checkbox("review_quality_check_active", 1, $active),
             '</span>Enable review quality checks</label></div>';
        echo '</div>';
    }

    static function print_form(SettingValues $sv) {
        $conf = $sv->conf;
        $qcform = $conf->review_quality_form();

        echo '<div class="settings-g">';
        echo '<h3 class="form-h">Quality check form</h3>';
        echo '<p>Define the form fields that meta-reviewers fill out when checking review quality. ',
             'The form is configured as JSON in the same format as the review form.</p>';

        $json = $conf->review_quality_form_json();
        $json_str = $json ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : "";

        echo '<div class="f-i">';
        echo '<textarea name="review_quality_form_json" id="review_quality_form_json" ',
             'class="w-text need-autogrow" rows="12" cols="80">',
             htmlspecialchars($json_str),
             '</textarea>';
        echo '</div>';

        echo '<div class="f-i"><strong>Default form:</strong> If left empty, a default form will be used with fields for review completeness, constructiveness, feedback for reviewer, and notes for chairs.</div>';
        echo '</div>';
    }

    static function print_acl(SettingValues $sv) {
        echo '<div class="settings-g">';
        echo '<h3 class="form-h">Quality check visibility</h3>';
        echo '<p>Quality check feedback is visible to:</p>';
        echo '<ul class="x">';
        echo '<li>The meta-reviewer who created the check</li>';
        echo '<li>The reviewer whose review was checked (limited to feedback directed at them)</li>';
        echo '<li>Chairs and administrators (full access to all checks)</li>';
        echo '</ul>';
        echo '<p>Quality check data is <strong>not</strong> visible to other PC members or authors.</p>';
        echo '</div>';
    }

    function store_value(Si $si, SettingValues $sv) {
        if ($si->name === "review_quality_check_active") {
            return;
        }
    }
}

class QualityCheckForm_SettingParser extends SettingParser {
    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "review_quality_form_json") {
            $json_str = trim($sv->reqstr($si->name) ?? "");
            if ($json_str === "") {
                return true;
            }
            $parsed = json_decode($json_str);
            if ($parsed === null) {
                $sv->error_at($si, "<0>Invalid JSON for quality check form");
                return false;
            }
            $sv->save("review_quality_form", json_encode($parsed));
            return true;
        }
        return false;
    }
}
