<?php
// settings/s_decisionvisibility.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class DecisionVisibility_SettingParser extends SettingParser {
    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name === "decision_visibility") {
            if ($sv->conf->time_all_author_view_decision()) {
                $sv->set_oldv($si, 2);
            } else {
                $sv->set_oldv($si, $sv->conf->setting("seedec") ?? 0);
            }
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "decision_visibility"
            && ($v = $sv->base_parse_req($si)) !== null) {
            if ($v === 2) {
                $sv->save("decision_visibility_author", 2);
                $sv->save("decision_visibility_reviewer", 1);
            } else {
                $sv->save("decision_visibility_author", 0);
                $sv->save("decision_visibility_reviewer", $v);
            }
            return true;
        }
        return false;
    }


    static function print(SettingValues $sv) {
        $extrev_view = $sv->vstr("review_visibility_external");
        $Rtext = $extrev_view ? "Reviewers" : "PC reviewers";
        $rtext = $extrev_view ? "reviewers" : "PC reviewers";
        $accept_auview = $sv->vstr("accepted_author_visibility")
            && $sv->vstr("author_visibility") != Conf::BLIND_NEVER;
        $sv->print_radio_table("decision_visibility", [
                0 => "Only administrators",
                3 => "$Rtext and non-conflicted PC members",
                1 => "$Rtext and <em>all</em> PC members",
                2 => "<b>Authors</b>, $rtext, and all PC members<span class=\"fx fn2\"> (and reviewers can see accepted submissions’ author lists)</span>"
            ], 'Who can see <strong>decisions</strong> (accept/reject)?',
            ["group_class" => $accept_auview ? "fold2c" : "fold2o",
             "fold_values" => [2],
             "item_class" => "uich js-foldup js-settings-seedec"]);
    }

    static function crosscheck(SettingValues $sv) {
        $conf = $sv->conf;
        if ($sv->has_interest("decision_visibility")
            && $conf->time_all_author_view_decision()
            && $sv->oldv("review_visibility_author") === Conf::AUSEEREV_NO) {
            $sv->warning_at(null, "<5>Authors can " . $sv->setting_link("see decisions", "decision_visibility") . ", but " . $sv->setting_link("not reviews", "review_visibility_author") . ". This is sometimes unintentional.");
        }

        if (($sv->has_interest("decision_visibility") || $sv->has_interest("submission_done"))
            && $sv->oldv("submission_open")
            && $sv->oldv("submission_done") > Conf::$now
            && !$conf->time_all_author_view_decision()
            && $conf->fetch_value("select paperId from Paper where outcome<0 limit 1") > 0) {
            $sv->warning_at(null, "<0>Updates will not be allowed for rejected submissions. As a result, authors can discover information about decisions that would otherwise be hidden.");
        }

        if ($sv->has_interest("review_visibility_author")
            && $sv->oldv("review_visibility_author") !== Conf::AUSEEREV_NO
            && !array_filter($conf->review_form()->all_fields(), function ($f) {
                return $f->view_score >= VIEWSCORE_AUTHORDEC;
            })) {
            $sv->warning_at(null, "<5>" . $sv->setting_link("Authors can see reviews", "review_visibility_author")
                . ", but the reviews have no author-visible fields. This is sometimes unintentional; you may want to update "
                . $sv->setting_link("the review form", "rf") . ".");
            $sv->warning_at("review_visibility_author");
        } else if ($sv->has_interest("review_visibility_author")
                   && $sv->oldv("review_visibility_author") !== Conf::AUSEEREV_NO
                   && !$conf->time_all_author_view_decision()
                   && !array_filter($conf->review_form()->all_fields(), function ($f) {
                       return $f->view_score >= VIEWSCORE_AUTHOR;
                   })
                   && array_filter($conf->review_form()->all_fields(), function ($f) {
                       return $f->view_score >= VIEWSCORE_AUTHORDEC;
                   })) {
            $sv->warning_at(null, "<5>" . $sv->setting_link("Authors can see reviews", "review_visibility_author")
                . ", but since "
                . $sv->setting_link("they cannot see decisions", "decision_visibility")
                . ", the reviews have no author-visible fields. This is sometimes unintentional; you may want to update "
                . $sv->setting_link("the review form", "rf") . ".");
            $sv->warning_at("review_visibility_author");
            $sv->warning_at("decision_visibility");
        }
    }
}
