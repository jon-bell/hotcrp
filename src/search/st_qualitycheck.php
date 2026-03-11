<?php
// search/st_qualitycheck.php -- HotCRP helper class for searching quality checks
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class QualityCheck_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var ?int */
    private $status;
    /** @var ?string */
    private $count_comparator;
    /** @var ?int */
    private $count_value;
    /** @var ?string */
    private $field_id;
    /** @var ?string */
    private $field_comparator;
    /** @var ?int */
    private $field_value;

    function __construct(Contact $user, $kwdef) {
        parent::__construct("qualitycheck");
        $this->user = $user;
        $this->status = $kwdef->status ?? null;
        $this->count_comparator = $kwdef->count_comparator ?? null;
        $this->count_value = $kwdef->count_value ?? null;
        $this->field_id = $kwdef->field_id ?? null;
        $this->field_comparator = $kwdef->field_comparator ?? null;
        $this->field_value = $kwdef->field_value ?? null;
    }

    static function keyword_factory($keyword, XtParams $xtp, $kwfj, $m) {
        return (object) [
            "name" => $keyword,
            "parse_function" => "QualityCheck_SearchTerm::parse"
        ];
    }

    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $user = $srch->user;
        $kwdef = (object) [];

        if ($word === "any" || $word === "" || $word === "yes") {
            $kwdef->count_comparator = ">";
            $kwdef->count_value = 0;
        } else if ($word === "none" || $word === "no") {
            $kwdef->count_comparator = "=";
            $kwdef->count_value = 0;
        } else if ($word === "needswork" || $word === "needs-work" || $word === "needs_work") {
            $kwdef->status = ReviewQualityCheckInfo::STATUS_NEEDS_WORK;
        } else if ($word === "improved") {
            $kwdef->status = ReviewQualityCheckInfo::STATUS_IMPROVED;
        } else if ($word === "pending") {
            $kwdef->status = ReviewQualityCheckInfo::STATUS_PENDING;
        } else if (preg_match('/\A([a-z]\d{2})\s*([<>=!]+)\s*(\d+)\z/', $word, $m)) {
            $kwdef->field_id = $m[1];
            $kwdef->field_comparator = CountMatcher::canonical_comparator($m[2]);
            $kwdef->field_value = (int) $m[3];
        } else if (preg_match('/\A([<>=!]+)\s*(\d+)\z/', $word, $m)) {
            $kwdef->count_comparator = CountMatcher::canonical_comparator($m[1]);
            $kwdef->count_value = (int) $m[2];
        } else {
            $kwdef->count_comparator = ">";
            $kwdef->count_value = 0;
        }

        return new QualityCheck_SearchTerm($user, $kwdef);
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_column("paperId", "Paper.paperId");
        if ($this->count_comparator === "=" && $this->count_value === 0 && $this->status === null) {
            return "Paper.paperId not in (select paperId from ReviewQualityCheck)";
        }
        if ($this->status !== null) {
            return "Paper.paperId in (select paperId from ReviewQualityCheck where status=" . $this->status . ")";
        }
        if ($this->field_id !== null && $this->field_comparator !== null) {
            $fid = $this->field_id;
            if (preg_match('/\As(0[1-6])\z/', $fid)) {
                return "Paper.paperId in (select paperId from ReviewQualityCheck where {$fid}{$this->field_comparator}{$this->field_value})";
            }
            return "Paper.paperId in (select paperId from ReviewQualityCheck where tfields is not null)";
        }
        return "Paper.paperId in (select paperId from ReviewQualityCheck)";
    }

    function test(PaperInfo $row, $xinfo) {
        $checks = ReviewQualityCheckInfo::fetch_by_paper($this->user->conf, $row->paperId);

        if ($this->status !== null) {
            foreach ($checks as $rqc) {
                if ($rqc->status === $this->status) {
                    return true;
                }
            }
            return false;
        }

        if ($this->field_id !== null && $this->field_comparator !== null) {
            foreach ($checks as $rqc) {
                $fval = $rqc->fval($this->field_id);
                if ($fval !== null && CountMatcher::compare((int) $fval, $this->field_comparator, $this->field_value)) {
                    return true;
                }
            }
            return false;
        }

        $count = count($checks);
        if ($this->count_comparator !== null) {
            return CountMatcher::compare($count, $this->count_comparator, $this->count_value);
        }
        return $count > 0;
    }

    function about() {
        return self::ABOUT_REVIEW;
    }
}
