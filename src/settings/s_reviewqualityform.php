<?php
// settings/s_reviewqualityform.php -- HotCRP review quality form settings
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ReviewQualityForm_SettingParser extends SettingParser {

    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name === "review_quality_form") {
            $sv->set_oldv($si, $sv->conf->setting_data("review_quality_form") ?? "[]");
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "review_quality_enabled") {
            $v = $sv->reqstr($si->name);
            $sv->save($si, $v ? 1 : null);
            return true;
        }
        if ($si->name === "review_quality_viewer_tags") {
            $v = $sv->reqstr($si->name);
            $sv->save($si, $v !== "" ? trim($v) : null);
            return true;
        }
        if ($si->name === "review_quality_editor_tags") {
            $v = $sv->reqstr($si->name);
            $sv->save($si, $v !== "" ? trim($v) : null);
            return true;
        }
        if ($si->name === "review_quality_form") {
            return $this->apply_form($si, $sv);
        }
        return false;
    }

    private function apply_form(Si $si, SettingValues $sv) {
        $form_json = $sv->reqstr("review_quality_form_json");
        if ($form_json === null || $form_json === "") {
            $form_json = "[]";
        }
        $fields = json_decode($form_json);
        if (!is_array($fields)) {
            $sv->error_at($si->name, "<0>Invalid form JSON");
            return true;
        }
        $clean = [];
        $ids_used = [];
        foreach ($fields as $i => $field) {
            if (!is_object($field)) {
                continue;
            }
            $f = (object) [];
            $f->id = $field->id ?? "";
            if ($f->id === "" || isset($ids_used[$f->id])) {
                $prefix = ($field->type ?? "text") === "text" ? "t" : "s";
                $n = 1;
                while (isset($ids_used[$prefix . sprintf("%02d", $n)])) {
                    $n++;
                }
                $f->id = $prefix . sprintf("%02d", $n);
            }
            $ids_used[$f->id] = true;
            $f->name = $field->name ?? "Field " . ($i + 1);
            $f->type = $field->type ?? "text";
            if (isset($field->description)) {
                $f->description = $field->description;
            }
            if (isset($field->required)) {
                $f->required = (bool) $field->required;
            }
            if (isset($field->values) && is_array($field->values)) {
                $f->values = $field->values;
            }
            if (isset($field->visibility)) {
                $f->visibility = $field->visibility;
            }
            if (isset($field->display_space)) {
                $f->display_space = (int) $field->display_space;
            }
            $f->order = $field->order ?? ($i + 1);
            $clean[] = $f;
        }

        usort($clean, function ($a, $b) {
            return ($a->order ?? 0) - ($b->order ?? 0);
        });

        $sv->update("review_quality_form", json_encode($clean));
        return true;
    }

    static function print(SettingValues $sv) {
        echo '<div class="form-g">';

        echo '<div class="f-i">';
        echo '<label>', Ht::checkbox("review_quality_enabled", 1,
            !!$sv->conf->setting("review_quality_enabled"),
            ["class" => "uich"]),
            ' Enable review quality checks</label>';
        echo '<div class="f-h">Allow meta reviewers and chairs to conduct quality checks on reviews.</div>';
        echo '</div>';

        echo '<div class="f-i">';
        echo '<label for="review_quality_viewer_tags">Viewer tags</label>';
        echo '<div class="f-h">Users with these tags can view quality checks (space-separated). Leave empty for default (meta reviewers only).</div>';
        echo Ht::entry("review_quality_viewer_tags",
            $sv->conf->setting_data("review_quality_viewer_tags") ?? "",
            ["id" => "review_quality_viewer_tags", "size" => 50, "class" => "w-text"]);
        echo '</div>';

        echo '<div class="f-i">';
        echo '<label for="review_quality_editor_tags">Editor tags</label>';
        echo '<div class="f-h">Users with these tags can create/edit quality checks (space-separated). Leave empty for default (meta reviewers only).</div>';
        echo Ht::entry("review_quality_editor_tags",
            $sv->conf->setting_data("review_quality_editor_tags") ?? "",
            ["id" => "review_quality_editor_tags", "size" => 50, "class" => "w-text"]);
        echo '</div>';

        echo '<div class="f-i">';
        echo '<h3>Review Quality Form</h3>';
        echo '<div class="f-h">Define the fields that meta reviewers fill in when performing quality checks. Enter as JSON array.</div>';

        $form_json = $sv->conf->setting_data("review_quality_form") ?? "[]";
        $fields = json_decode($form_json);
        if (!is_array($fields) || empty($fields)) {
            $default_form = [
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
                    "id" => "s02", "name" => "Constructiveness", "type" => "radio",
                    "order" => 2, "description" => "How constructive is the feedback?",
                    "values" => [
                        (object) ["symbol" => "1", "name" => "Not constructive"],
                        (object) ["symbol" => "2", "name" => "Somewhat constructive"],
                        (object) ["symbol" => "3", "name" => "Constructive"],
                        (object) ["symbol" => "4", "name" => "Very constructive"],
                        (object) ["symbol" => "5", "name" => "Exceptionally constructive"]
                    ]
                ],
                (object) [
                    "id" => "t01", "name" => "Detailed feedback", "type" => "text",
                    "order" => 3, "description" => "Detailed feedback on the review quality",
                    "display_space" => 5
                ]
            ];
            $form_json = json_encode($default_form, JSON_PRETTY_PRINT);
        } else {
            $form_json = json_encode($fields, JSON_PRETTY_PRINT);
        }

        echo Ht::textarea("review_quality_form_json", $form_json, [
            "id" => "review_quality_form_json",
            "class" => "w-text need-autogrow",
            "rows" => 15,
            "cols" => 80
        ]);
        echo '</div>';

        self::print_form_preview($fields ?? []);

        echo '</div>';
    }

    private static function print_form_preview($fields) {
        if (empty($fields)) {
            return;
        }
        echo '<div class="f-i">';
        echo '<h3>Form Preview</h3>';
        echo '<div class="rqc-form-preview" style="border:1px solid #ccc;padding:1em;border-radius:4px;background:#fafafa">';
        foreach ($fields as $field) {
            echo '<div class="f-i" style="margin-bottom:0.8em">';
            echo '<label><strong>', htmlspecialchars($field->name ?? ''), '</strong></label>';
            if (!empty($field->description)) {
                echo '<div class="field-d" style="color:#666;font-size:0.9em">', htmlspecialchars($field->description), '</div>';
            }
            $type = $field->type ?? "text";
            if ($type === "text") {
                echo '<div style="border:1px solid #ddd;padding:0.5em;min-height:3em;background:white;border-radius:2px"><em style="color:#999">Text field</em></div>';
            } else if ($type === "radio" || $type === "dropdown") {
                if (!empty($field->values)) {
                    foreach ($field->values as $v) {
                        $label = is_object($v) ? (($v->symbol ?? '') . '. ' . ($v->name ?? '')) : $v;
                        echo '<div style="margin:0.2em 0"><span style="display:inline-block;width:1em;height:1em;border:1px solid #999;border-radius:50%;vertical-align:middle;margin-right:0.3em"></span> ', htmlspecialchars($label), '</div>';
                    }
                }
            }
            echo '</div>';
        }
        echo '</div></div>';
    }
}
