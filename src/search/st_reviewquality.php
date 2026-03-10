<?php
// search/st_reviewquality.php -- HotCRP search for review quality checks
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ReviewQuality_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var string */
    private $field;
    /** @var string */
    private $comparator;
    /** @var ?string */
    private $value;

    function __construct(Contact $user, $field, $comparator, $value) {
        parent::__construct("rqc");
        $this->user = $user;
        $this->field = $field;
        $this->comparator = $comparator;
        $this->value = $value;
    }

    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $user = $srch->user;
        if (!$user->can_view_review_quality_dashboard() && !$user->is_metareviewer()) {
            $srch->lwarning($sword, "<0>Review quality check searches require meta reviewer or chair access");
            return new False_SearchTerm;
        }

        $parts = explode(":", $word, 2);
        if (count($parts) < 2) {
            $parts = [$word, "any"];
        }
        $field = $parts[0];
        $val = $parts[1];

        if ($field === "status") {
            return self::parse_status($user, $val, $sword, $srch);
        } else if ($field === "count" || $field === "has" || $field === "any") {
            return self::parse_count($user, $val, $sword, $srch);
        } else if ($field === "checker" || $field === "meta") {
            return self::parse_checker($user, $val, $sword, $srch);
        } else {
            return self::parse_field($user, $field, $val, $sword, $srch);
        }
    }

    /** @return SearchTerm */
    private static function parse_status(Contact $user, $val, SearchWord $sword, PaperSearch $srch) {
        $status = ReviewQualityCheckInfo::$status_map[$val] ?? null;
        if ($status === null) {
            $srch->lwarning($sword, "<0>Unknown quality check status '{$val}'");
            return new False_SearchTerm;
        }
        return new self($user, "status", "=", (string) $status);
    }

    /** @return SearchTerm */
    private static function parse_count(Contact $user, $val, SearchWord $sword, PaperSearch $srch) {
        if ($val === "any" || $val === "yes") {
            return new self($user, "count", ">", "0");
        } else if ($val === "none" || $val === "no") {
            return new self($user, "count", "=", "0");
        } else if (($a = CountMatcher::unpack_comparison($val))) {
            return new self($user, "count", CountMatcher::unparse_relation($a[1]), $a[2]);
        }
        return new self($user, "count", ">", "0");
    }

    /** @return SearchTerm */
    private static function parse_checker(Contact $user, $val, SearchWord $sword, PaperSearch $srch) {
        return new self($user, "checker", "=", $val);
    }

    /** @return SearchTerm */
    private static function parse_field(Contact $user, $field, $val, SearchWord $sword, PaperSearch $srch) {
        $form_fields = $user->conf->review_quality_form_fields();
        $found = false;
        $fid = "";
        foreach ($form_fields as $f) {
            if (($f->id ?? "") === $field || strcasecmp($f->name ?? "", $field) === 0) {
                $fid = $f->id;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $srch->lwarning($sword, "<0>Unknown quality check field '{$field}'");
            return new False_SearchTerm;
        }
        if ($val === "any") {
            return new self($user, $fid, "!=", "");
        } else if ($val === "none" || $val === "empty") {
            return new self($user, $fid, "=", "");
        } else if (($a = CountMatcher::unpack_comparison($val))) {
            return new self($user, $fid, CountMatcher::unparse_relation($a[1]), $a[2]);
        } else {
            return new self($user, $fid, "~=", $val);
        }
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_column("paperQualityCheckInfo", "(select group_concat(reviewQualityCheckId, ';', reviewId, ';', status, ';', contactId, ';', coalesce(sfields,''), ';', coalesce(tfields,'') separator '|') from ReviewQualityCheck where ReviewQualityCheck.paperId=Paper.paperId)");
        return "exists (select * from ReviewQualityCheck where ReviewQualityCheck.paperId=Paper.paperId)";
    }

    function test(PaperInfo $prow, $xinfo) {
        if (!$this->user->can_view_review_quality_checks($prow)) {
            return false;
        }
        $checks = $prow->all_review_quality_checks();

        if ($this->field === "count") {
            $n = count($checks);
            return CountMatcher::compare($n, $this->comparator, (int) $this->value);
        }

        if ($this->field === "status") {
            foreach ($checks as $qc) {
                if ($qc->status === (int) $this->value) {
                    return true;
                }
            }
            return false;
        }

        if ($this->field === "checker") {
            foreach ($checks as $qc) {
                $checker = $qc->checker();
                if ($checker) {
                    if (strcasecmp($checker->email, $this->value) === 0
                        || ($this->value === "me" && $checker->contactId === $this->user->contactId)
                        || stripos($checker->name(NAME_E), $this->value) !== false) {
                        return true;
                    }
                }
            }
            return false;
        }

        foreach ($checks as $qc) {
            $val = $qc->field_value($this->field);
            if ($this->comparator === "!=") {
                if ($val !== null && $val !== "" && $val !== 0) {
                    return true;
                }
            } else if ($this->comparator === "=") {
                if ($val === null || $val === "" || $val === 0) {
                    return true;
                }
            } else if ($this->comparator === "~=") {
                if (is_string($val) && stripos($val, $this->value) !== false) {
                    return true;
                }
            } else {
                if (is_numeric($val) && CountMatcher::compare((int) $val, $this->comparator, (int) $this->value)) {
                    return true;
                }
            }
        }
        return false;
    }

    function about() {
        return self::ABOUT_PAPER;
    }

    static function keyword_factory($keyword, XtParams $xtp, $kwfj, $m) {
        return (object) [
            "name" => $keyword,
            "parse_function" => "ReviewQuality_SearchTerm::parse"
        ];
    }
}
