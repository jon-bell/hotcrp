<?php
// pc_qualitycheck.php -- HotCRP helper classes for quality check paper columns
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class QualityCheck_PaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->user->isPC || $pl->user->privChair;
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $ac = ReviewQualityCheckInfo::fetch_by_paper($pl->conf, $a->paperId);
        $bc = ReviewQualityCheckInfo::fetch_by_paper($pl->conf, $b->paperId);
        return count($ac) - count($bc);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $checks = ReviewQualityCheckInfo::fetch_by_paper($pl->conf, $row->paperId);
        if (empty($checks)) {
            return "";
        }
        $needs_work = 0;
        $improved = 0;
        $pending = 0;
        foreach ($checks as $rqc) {
            if ($rqc->is_needs_work()) {
                ++$needs_work;
            } else if ($rqc->is_improved()) {
                ++$improved;
            } else {
                ++$pending;
            }
        }
        $parts = [];
        if ($needs_work > 0) {
            $parts[] = '<span class="badge-warning">' . $needs_work . ' needs work</span>';
        }
        if ($improved > 0) {
            $parts[] = '<span class="badge-success">' . $improved . ' improved</span>';
        }
        if ($pending > 0) {
            $parts[] = $pending . ' pending';
        }
        return join(" ", $parts);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $checks = ReviewQualityCheckInfo::fetch_by_paper($pl->conf, $row->paperId);
        if (empty($checks)) {
            return "";
        }
        $needs_work = 0;
        $improved = 0;
        foreach ($checks as $rqc) {
            if ($rqc->is_needs_work()) {
                ++$needs_work;
            } else if ($rqc->is_improved()) {
                ++$improved;
            }
        }
        return count($checks) . " checks, " . $needs_work . " needs work, " . $improved . " improved";
    }

    static function expand($name, XtParams $xtp, $cj, $m) {
        return [(object) [
            "name" => "qualitycheck",
            "title" => "Quality checks",
            "column" => true,
            "className" => "pl_qualitycheck"
        ]];
    }
}
