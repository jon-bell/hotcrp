<?php
// reviewqualityform.php -- HotCRP helper class for review quality check forms
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class ReviewQualityForm {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var array<string,ReviewField>
     * @readonly */
    public $forder;
    /** @var array<string,ReviewField>
     * @readonly */
    public $fmap;
    /** @var array<string,ReviewField> */
    private $_by_main_storage;
    /** @var int
     * @readonly */
    private $_order_bound;

    static private $default_form_json = '[
        {"id":"s01","name":"Review completeness","order":1,"visibility":"admin","options":["Incomplete","Partially complete","Adequate","Thorough"]},
        {"id":"s02","name":"Constructiveness","order":2,"visibility":"admin","options":["Unconstructive","Minimally constructive","Constructive","Very constructive"]},
        {"id":"t01","name":"Feedback for reviewer","order":3,"visibility":"admin"},
        {"id":"t02","name":"Notes for chairs","order":4,"visibility":"admin"}
    ]';

    /** @param null|array|object $rfj */
    function __construct(Conf $conf, $rfj) {
        $this->conf = $conf;
        $this->fmap = $this->forder = [];

        if (!$rfj) {
            $rfj = json_decode(self::$default_form_json);
        }

        foreach ($rfj as $fid => $j) {
            if (is_int($fid)) {
                $fid = $j->id;
            }
            if (($finfo = ReviewFieldInfo::find($conf, $fid))) {
                $f = ReviewField::make_json($conf, $finfo, $j);
                $this->fmap[$f->short_id] = $f;
            }
        }
        uasort($this->fmap, "ReviewField::order_compare");

        $do = 0;
        foreach ($this->fmap as $f) {
            if ($f->order > 0) {
                $f->order = ++$do;
                $this->forder[$f->short_id] = $f;
                if ($f->main_storage !== null) {
                    $this->_by_main_storage[$f->main_storage] = $f;
                }
            }
        }
        $this->_order_bound = $do + 1;
    }

    /** @param string $fid
     * @return ?ReviewField */
    function field($fid) {
        return $this->forder[$fid] ?? ($this->_by_main_storage[$fid] ?? null);
    }

    /** @return array<string,ReviewField> */
    function all_fields() {
        return $this->forder;
    }

    /** @return list<ReviewField> */
    function viewable_fields(Contact $user) {
        $fs = [];
        foreach ($this->forder as $f) {
            $fs[] = $f;
        }
        return $fs;
    }

    /** @param ReviewQualityCheckInfo $rqc
     * @param Contact $user
     * @return void */
    function print_form($rqc, $user) {
        foreach ($this->forder as $f) {
            $fval = $rqc->fval($f->short_id);
            echo '<div class="revcard-rqfield" data-field="', $f->short_id, '">';
            echo '<h3 class="rfehead">', htmlspecialchars($f->name), '</h3>';
            if ($f->description) {
                echo '<div class="field-d">', $f->description, '</div>';
            }
            if ($f instanceof Score_ReviewField || $f instanceof DiscreteValues_ReviewField) {
                $this->_print_score_field($f, $fval);
            } else {
                $this->_print_text_field($f, $fval);
            }
            echo '</div>';
        }
    }

    /** @param ReviewField $f
     * @param mixed $val */
    private function _print_score_field($f, $val) {
        echo '<div class="revev">';
        $f->print_web_edit($val, "rqc");
        echo '</div>';
    }

    /** @param ReviewField $f
     * @param mixed $val */
    private function _print_text_field($f, $val) {
        echo '<div class="revev">';
        echo '<textarea name="rqc_', $f->short_id, '" class="w-text need-autogrow" rows="3">',
             htmlspecialchars($val ?? ""), '</textarea>';
        echo '</div>';
    }

    /** @param ReviewQualityCheckInfo $rqc
     * @return string */
    function unparse_text($rqc) {
        $t = "";
        foreach ($this->forder as $f) {
            $fval = $rqc->fval($f->short_id);
            $t .= "==+ " . $f->name . "\n";
            if ($f->is_sfield && $fval) {
                $t .= (string) $fval . "\n";
            } else if (!$f->is_sfield && $fval) {
                $t .= $fval . "\n";
            }
            $t .= "\n";
        }
        return $t;
    }
}
