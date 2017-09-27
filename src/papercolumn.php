<?php
// papercolumn.php -- HotCRP helper classes for paper list content
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperColumn extends Column {
    static private $by_name = [];
    static private $factories = [];
    static private $synonyms = [];
    static private $j_by_name = null;
    static private $j_factories = null;

    const PREP_SORT = -1;
    const PREP_FOLDED = 0; // value matters
    const PREP_VISIBLE = 1; // value matters

    function __construct($cj) {
        parent::__construct($cj);
    }

    static function lookup_json($name) {
        $lname = strtolower($name);
        if (isset(self::$synonyms[$lname]))
            $lname = self::$synonyms[$lname];
        return get(self::$j_by_name, $lname, null);
    }

    static function _add_json($cj) {
        if (is_object($cj) && isset($cj->name) && is_string($cj->name)) {
            self::$j_by_name[strtolower($cj->name)] = $cj;
            if (($syn = get($cj, "synonym")))
                foreach (is_string($syn) ? [$syn] : $syn as $x)
                    self::register_synonym($x, $cj->name);
            return true;
        } else if (is_object($cj) && isset($cj->prefix) && is_string($cj->prefix)) {
            self::$j_factories[] = $cj;
            return true;
        } else
            return false;
    }
    private static function _expand_json($cj) {
        $f = null;
        if (($factory_class = get($cj, "factory_class")))
            $f = new $factory_class($cj);
        else if (($factory = get($cj, "factory")))
            $f = call_user_func($factory, $cj);
        else
            return null;
        if (isset($cj->name))
            self::$by_name[strtolower($cj->name)] = $f;
        else {
            self::$factories[] = [strtolower($cj->prefix), $f];
            if (($syn = get($cj, "synonym")))
                foreach (is_string($syn) ? [$syn] : $syn as $x)
                    self::$factories[] = [strtolower($x), $f];
        }
        return $f;
    }
    private static function _sort_factories() {
        usort(self::$factories, function ($a, $b) {
            $ldiff = strlen($b[0]) - strlen($a[0]);
            if (!$ldiff)
                $ldiff = $a[1]->priority - $b[1]->priority;
            return $ldiff < 0 ? -1 : ($ldiff > 0 ? 1 : 0);
        });
    }

    private static function _populate_json() {
        self::$j_by_name = self::$j_factories = [];
        expand_json_includes_callback(["etc/papercolumns.json"], "PaperColumn::_add_json");
        if (($jlist = opt("paperColumns")))
            expand_json_includes_callback($jlist, "PaperColumn::_add_json");
    }
    static function lookup(Contact $user, $name, $errors = null) {
        $lname = strtolower($name);
        if (isset(self::$synonyms[$lname]))
            $lname = self::$synonyms[$lname];

        // columns by name
        if (isset(self::$by_name[$lname]))
            return self::$by_name[$lname];
        if (self::$j_by_name === null)
            self::_populate_json();
        if (isset(self::$j_by_name[$lname]))
            return self::_expand_json(self::$j_by_name[$lname]);

        // columns by factory
        foreach (self::lookup_all_factories() as $fax)
            if (str_starts_with($lname, $fax[0])
                && ($f = $fax[1]->instantiate($user, $name, $errors)))
                return $f;
        return null;
    }

    static function register($fdef) {
        $lname = strtolower($fdef->name);
        assert(!isset(self::$by_name[$lname]) && !isset(self::$synonyms[$lname]));
        self::$by_name[$lname] = $fdef;
        assert(func_num_args() == 1); // XXX backwards compat
        return $fdef;
    }
    static function register_synonym($new_name, $old_name) {
        $lold = strtolower($old_name);
        $lname = strtolower($new_name);
        assert((isset(self::$by_name[$lold]) || isset(self::$j_by_name[$lold]))
               && !isset(self::$by_name[$lname]) && !isset(self::$synonyms[$lname]));
        self::$synonyms[$lname] = $lold;
    }

    static function lookup_all() {
        if (self::$j_by_name === null)
            self::_populate_json();
        foreach (self::$j_by_name as $name => $j)
            if (!isset(self::$by_name[$name]))
                self::_expand_json($j);
        return self::$by_name;
    }
    static function lookup_all_factories() {
        if (self::$j_by_name === null)
            self::_populate_json();
        if (self::$j_factories) {
            while (($fj = array_shift(self::$j_factories)))
                self::_expand_json($fj);
            self::_sort_factories();
        }
        return self::$factories;
    }


    function make_editable() {
        return $this;
    }

    function prepare(PaperList $pl, $visible) {
        return true;
    }
    function realize(PaperList $pl) {
        return $this;
    }
    function annotate_field_js(PaperList $pl, &$fjs) {
    }

    function analyze(PaperList $pl, &$rows, $fields) {
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        error_log("unexpected compare " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        return $a->paperId - $b->paperId;
    }

    function header(PaperList $pl, $is_text) {
        if ($is_text)
            return "<" . $this->name . ">";
        else
            return "&lt;" . htmlspecialchars($this->name) . "&gt;";
    }
    function completion_name() {
        if (!$this->completion)
            return false;
        else if (is_string($this->completion))
            return $this->completion;
        else
            return $this->name;
    }
    function sort_name($score_sort) {
        return $this->name;
    }
    function alternate_display_name() {
        return false;
    }

    function content_empty(PaperList $pl, PaperInfo $row) {
        return false;
    }

    function content(PaperList $pl, PaperInfo $row) {
        return "";
    }
    function text(PaperList $pl, PaperInfo $row) {
        return "";
    }

    function has_statistics() {
        return false;
    }
}

class PaperColumnFactory {
    public $priority;
    function __construct($cj = null) {
        $this->priority = get_f($cj, "priority");
    }
    function instantiate(Contact $user, $name, $errors) {
    }
    function completion_instances(Contact $user) {
        return [$this];
    }
    function completion_name() {
        return false;
    }
    static function instantiate_error($errors, $ehtml, $eprio) {
        $errors && $errors->add($ehtml, $eprio);
    }
}

class IdPaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function header(PaperList $pl, $is_text) {
        return "ID";
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        return $a->paperId - $b->paperId;
    }
    function content(PaperList $pl, PaperInfo $row) {
        $href = $pl->_paperLink($row);
        return "<a href=\"$href\" class=\"pnum taghl\" tabindex=\"4\">#$row->paperId</a>";
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->paperId;
    }
}

class SelectorPaperColumn extends PaperColumn {
    public $is_selector = true;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function header(PaperList $pl, $is_text) {
        return $is_text ? "Selected" : "";
    }
    protected function checked(PaperList $pl, PaperInfo $row) {
        return $pl->is_selected($row->paperId, $this->name == "selon");
    }
    function content(PaperList $pl, PaperInfo $row) {
        $pl->mark_has("sel");
        $c = "";
        if ($this->checked($pl, $row)) {
            $c .= ' checked="checked"';
            unset($row->folded);
        }
        return '<span class="pl_rownum fx6">' . $pl->count . '. </span>'
            . '<input type="checkbox" class="cb" name="pap[]" value="' . $row->paperId . '" tabindex="3" onclick="rangeclick(event,this)"' . $c . ' />';
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $this->checked($pl, $row) ? "Y" : "N";
    }
}

class ConflictSelector_PaperColumn extends SelectorPaperColumn {
    private $contact;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $pl->reviewer_user();
        if (!$pl->user->is_manager())
            return false;
        if (($tid = $pl->table_id()))
            $pl->add_header_script("add_assrev_ajax(" . json_encode_browser("#$tid") . ")");
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Conflict?";
    }
    protected function checked(PaperList $pl, PaperInfo $row) {
        return $pl->is_selected($row->paperId, $row->conflict_type($this->contact) > 0);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $disabled = $row->conflict_type($this->contact) >= CONFLICT_AUTHOR;
        if (!$pl->user->allow_administer($row)) {
            $disabled = true;
            if (!$pl->user->can_view_conflicts($row))
                return "";
        }
        $pl->mark_has("sel");
        $c = "";
        if ($disabled)
            $c .= ' disabled="disabled"';
        if ($this->checked($pl, $row)) {
            $c .= ' checked="checked"';
            unset($row->folded);
        }
        return '<input type="checkbox" class="cb" '
            . 'name="assrev' . $row->paperId . 'u' . $this->contact->contactId
            . '" value="-1" tabindex="3"' . $c . ' />';
    }
}

class TitlePaperColumn extends PaperColumn {
    private $has_decoration = false;
    private $highlight = false;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        $this->has_decoration = $pl->user->can_view_tags(null)
            && $pl->conf->tags()->has_decoration;
        if ($this->has_decoration)
            $pl->qopts["tags"] = 1;
        $this->highlight = $pl->search->field_highlighter("title");
        return true;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $cmp = strcasecmp($a->unaccented_title(), $b->unaccented_title());
        if (!$cmp)
            $cmp = strcasecmp($a->title, $b->title);
        return $cmp;
    }
    function header(PaperList $pl, $is_text) {
        return "Title";
    }
    function content(PaperList $pl, PaperInfo $row) {
        $t = '<a href="' . $pl->_paperLink($row) . '" class="ptitle taghl';

        $highlight_text = Text::highlight($row->title, $this->highlight, $highlight_count);

        if (!$highlight_count && ($format = $row->title_format())) {
            $pl->need_render = true;
            $t .= ' need-format" data-format="' . $format
                . '" data-title="' . htmlspecialchars($row->title);
        }

        $t .= '" tabindex="5">' . $highlight_text . '</a>'
            . $pl->_contentDownload($row);

        if ($this->has_decoration && (string) $row->paperTags !== "") {
            if ($row->conflictType > 0 && $pl->user->allow_administer($row)) {
                if (($vto = $row->viewable_tags($pl->user, true))
                    && ($deco = $pl->tagger->unparse_decoration_html($vto))) {
                    $vtx = $row->viewable_tags($pl->user, false);
                    $decx = $pl->tagger->unparse_decoration_html($vtx);
                    if ($deco !== $decx) {
                        if ($decx)
                            $t .= '<span class="fn5">' . $decx . '</span>';
                        $t .= '<span class="fx5">' . $deco . '</span>';
                    } else
                        $t .= $deco;
                }
            } else if (($vt = $row->viewable_tags($pl->user)))
                $t .= $pl->tagger->unparse_decoration_html($vt);
        }

        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->title;
    }
}

class StatusPaperColumn extends PaperColumn {
    private $is_long;
    function __construct($cj) {
        parent::__construct($cj);
        $this->is_long = $cj->name === "statusfull";
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        $force = $pl->search->limitName != "a" && $pl->user->privChair;
        foreach ($rows as $row)
            if ($row->outcome && $pl->user->can_view_decision($row, $force))
                $row->_status_sort_info = $row->outcome;
            else
                $row->_status_sort_info = -10000;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $x = $b->_status_sort_info - $a->_status_sort_info;
        $x = $x ? $x : ($a->timeWithdrawn > 0) - ($b->timeWithdrawn > 0);
        $x = $x ? $x : ($b->timeSubmitted > 0) - ($a->timeSubmitted > 0);
        return $x ? $x : ($b->paperStorageId > 1) - ($a->paperStorageId > 1);
    }
    function header(PaperList $pl, $is_text) {
        return "Status";
    }
    function content(PaperList $pl, PaperInfo $row) {
        if ($row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0)
            $pl->mark_has("need_submit");
        if ($row->outcome > 0 && $pl->user->can_view_decision($row))
            $pl->mark_has("accepted");
        if ($row->outcome > 0 && $row->timeFinalSubmitted <= 0
            && $pl->user->can_view_decision($row))
            $pl->mark_has("need_final");
        $status_info = $pl->user->paper_status_info($row, $pl->search->limitName != "a" && $pl->user->allow_administer($row));
        if (!$this->is_long && $status_info[0] == "pstat_sub")
            return "";
        return "<span class=\"pstat $status_info[0]\">" . htmlspecialchars($status_info[1]) . "</span>";
    }
    function text(PaperList $pl, PaperInfo $row) {
        $status_info = $pl->user->paper_status_info($row, $pl->search->limitName != "a" && $pl->user->allow_administer($row));
        return $status_info[1];
    }
}

class ReviewStatus_PaperColumn extends PaperColumn {
    private $round;
    function __construct($cj) {
        parent::__construct($cj);
        $this->round = get($cj, "round", null);
    }
    function prepare(PaperList $pl, $visible) {
        if ($pl->user->privChair || $pl->user->is_reviewer() || $pl->conf->timeAuthorViewReviews()) {
            $pl->qopts["reviewSignatures"] = true;
            return true;
        } else
            return false;
    }
    private function data(PaperInfo $row, Contact $user) {
        $want_assigned = $user->privChair || !$row->conflict_type($user);
        $done = $started = 0;
        foreach ($row->reviews_by_id() as $rrow)
            if ($user->can_view_review_assignment($row, $rrow, null)
                && ($this->round === null || $this->round === $rrow->reviewRound)) {
                if ($rrow->reviewSubmitted > 0) {
                    ++$done;
                    ++$started;
                } else if ($want_assigned ? $rrow->reviewNeedsSubmit > 0 : $rrow->reviewModified > 0)
                    ++$started;
            }
        return [$done, $started];
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        foreach ($rows as $row) {
            if (!$pl->user->can_view_review_assignment($row, null, null))
                $row->_review_status_sort_info = -2147483647;
            else {
                list($done, $started) = $this->data($row, $pl->user);
                $row->_review_status_sort_info = $done + $started / 1000.0;
            }
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $av = $a->_review_status_sort_info;
        $bv = $b->_review_status_sort_info;
        return ($av < $bv ? 1 : ($av == $bv ? 0 : -1));
    }
    function header(PaperList $pl, $is_text) {
        $round_name = "";
        if ($this->round !== null)
            $round_name = ($pl->conf->round_name($this->round) ? : "unnamed") . " ";
        if ($is_text)
            return "# {$round_name}Reviews";
        else
            return '<span class="need-tooltip" data-tooltip="# completed reviews / # assigned reviews" data-tooltip-dir="b">#&nbsp;' . $round_name . 'Reviews</span>';
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_review_assignment($row, null, null);
    }
    function content(PaperList $pl, PaperInfo $row) {
        list($done, $started) = $this->data($row, $pl->user);
        return "<b>$done</b>" . ($done == $started ? "" : "/$started");
    }
    function text(PaperList $pl, PaperInfo $row) {
        list($done, $started) = $this->data($row, $pl->user);
        return $done . ($done == $started ? "" : "/$started");
    }
}

class ReviewStatus_PaperColumnFactory extends PaperColumnFactory {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function instantiate(Contact $user, $name, $errors) {
        if (!$user->is_reviewer())
            return null;
        $colon = strpos($name, ":");
        $rname = substr($name, $colon + 1);
        $revstat = (array) PaperColumn::lookup_json("revstat");
        if (preg_match('/\A(?:any|all)\z/i', $rname))
            return [new ReviewStatus_PaperColumn($revstat)];
        $round = $user->conf->round_number($rname, false);
        if ($round === false) {
            self::instantiate_error($errors, "No review round matches “" . htmlspecialchars($rname) . "”.", 2);
            return null;
        }
        $fname = substr($name, 0, $colon + 1) . ($user->conf->round_name($round) ? : "unnamed");
        return [new ReviewStatus_PaperColumn(["name" => $fname, "round" => $round] + $revstat)];
    }
}

class AuthorsPaperColumn extends PaperColumn {
    private $aufull;
    private $anonau;
    private $highlight;
    private $forceable;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function header(PaperList $pl, $is_text) {
        return "Authors";
    }
    function prepare(PaperList $pl, $visible) {
        $this->aufull = !$pl->is_folded("aufull");
        $this->anonau = !$pl->is_folded("anonau");
        $this->highlight = $pl->search->field_highlighter("authorInformation");
        $this->forceable = $pl->user->is_manager() ? true : null;
        return $pl->user->can_view_some_authors();
    }
    private function affiliation_map($row) {
        $nonempty_count = 0;
        $aff = [];
        foreach ($row->author_list() as $i => $au) {
            if ($i && $au->affiliation === $aff[$i - 1])
                $aff[$i - 1] = null;
            $aff[] = $au->affiliation;
            $nonempty_count += ($au->affiliation !== "");
        }
        if ($nonempty_count != 0 && $nonempty_count != count($aff)) {
            foreach ($aff as &$affx)
                if ($affx === "")
                    $affx = "unaffiliated";
        }
        return $aff;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_authors($row, $this->forceable);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $out = [];
        if (!$this->highlight && !$this->aufull) {
            foreach ($row->author_list() as $au)
                $out[] = $au->abbrevname_html();
            $t = join(", ", $out);
        } else {
            $affmap = $this->affiliation_map($row);
            $aus = $affout = [];
            $any_affhl = false;
            foreach ($row->author_list() as $i => $au) {
                $name = Text::highlight($au->name(), $this->highlight, $didhl);
                if (!$this->aufull
                    && ($first = htmlspecialchars($au->firstName))
                    && (!$didhl || substr($name, 0, strlen($first)) === $first)
                    && ($initial = Text::initial($first)) !== "")
                    $name = $initial . substr($name, strlen($first));
                $auy[] = $name;
                if ($affmap[$i] !== null) {
                    $out[] = join(", ", $auy);
                    $affout[] = Text::highlight($affmap[$i], $this->highlight, $didhl);
                    $any_affhl = $any_affhl || $didhl;
                    $auy = [];
                }
            }
            // $affout[0] === "" iff there are no nonempty affiliations
            if (($any_affhl || $this->aufull) && $affout[0] !== "") {
                foreach ($out as $i => &$x)
                    $x .= ' <span class="auaff">(' . $affout[$i] . ')</span>';
            }
            $t = join($any_affhl || $this->aufull ? "; " : ", ", $out);
        }
        if ($this->forceable && !$pl->user->can_view_authors($row, false))
            $t = '<div class="fx2">' . $t . '</div>';
        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        if (!$pl->user->can_view_authors($row) && !$this->anonau)
            return "";
        $out = [];
        if (!$this->aufull) {
            foreach ($row->author_list() as $au)
                $out[] = $au->abbrevname_text();
            return join("; ", $out);
        } else {
            $affmap = $this->affiliation_map($row);
            $aus = [];
            foreach ($row->author_list() as $i => $au) {
                $aus[] = $au->name();
                if ($affmap[$i] !== null) {
                    $aff = ($affmap[$i] !== "" ? " ($affmap[$i])" : "");
                    $out[] = commajoin($aus) . $aff;
                    $aus = [];
                }
            }
            return join("; ", $out);
        }
    }
}

class CollabPaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        return !!$pl->conf->setting("sub_collab") && $pl->user->can_view_some_authors();
    }
    function header(PaperList $pl, $is_text) {
        return "Collaborators";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return ($row->collaborators == ""
                || strcasecmp($row->collaborators, "None") == 0
                || !$pl->user->can_view_authors($row, true));
    }
    function content(PaperList $pl, PaperInfo $row) {
        $x = "";
        foreach (explode("\n", $row->collaborators) as $c)
            $x .= ($x === "" ? "" : ", ") . trim($c);
        return Text::highlight($x, $pl->search->field_highlighter("collaborators"));
    }
    function text(PaperList $pl, PaperInfo $row) {
        $x = "";
        foreach (explode("\n", $row->collaborators) as $c)
            $x .= ($x === "" ? "" : ", ") . trim($c);
        return $x;
    }
}

class Abstract_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function header(PaperList $pl, $is_text) {
        return "Abstract";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return $row->abstract == "";
    }
    function content(PaperList $pl, PaperInfo $row) {
        $t = Text::highlight($row->abstract, $pl->search->field_highlighter("abstract"), $highlight_count);
        $klass = strlen($t) > 190 ? "pl_longtext" : "pl_shorttext";
        if (!$highlight_count && ($format = $row->format_of($row->abstract))) {
            $pl->need_render = true;
            $t = '<div class="' . $klass . ' need-format" data-format="'
                . $format . '.abs.plx">' . $t . '</div>';
        } else
            $t = '<div class="' . $klass . '">' . Ht::format0($t) . '</div>';
        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->abstract;
    }
}

class TopicListPaperColumn extends PaperColumn {
    private $interest_contact;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->conf->has_topics())
            return false;
        if ($visible)
            $pl->qopts["topics"] = 1;
        // only managers can see other users’ topic interests
        $this->interest_contact = $pl->reviewer_user();
        if ($this->interest_contact->contactId !== $pl->user->contactId
            && !$pl->user->is_manager())
            $this->interest_contact = null;
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Topics";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !isset($row->topicIds) || $row->topicIds == "";
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $row->unparse_topics_html(true, $this->interest_contact);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->unparse_topics_text();
    }
}

class ReviewerTypePaperColumn extends PaperColumn {
    protected $contact;
    private $self;
    private $rrow_key;
    function __construct($cj, $contact = null) {
        parent::__construct($cj);
        $this->contact = $contact;
    }
    function contact() {
        return $this->contact;
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $this->contact ? : $pl->context_user();
        $this->self = $this->contact->contactId === $pl->user->contactId;
        return true;
    }
    const F_CONFLICT = 1;
    const F_LEAD = 2;
    const F_SHEPHERD = 4;
    private function analysis(PaperList $pl, PaperInfo $row, $forceShow = null) {
        $rrow = $row->review_of_user($this->contact);
        if ($rrow && ($this->self || $pl->user->can_view_review_identity($row, $rrow, $forceShow)))
            $ranal = $pl->make_review_analysis($rrow, $row);
        else
            $ranal = null;
        if ($ranal && !$ranal->rrow->reviewSubmitted)
            $pl->mark_has("need_review");
        $flags = 0;
        if ($row->conflict_type($this->contact)
            && ($this->self || $pl->user->can_view_conflicts($row, $forceShow)))
            $flags |= self::F_CONFLICT;
        if ($row->leadContactId == $this->contact->contactId
            && ($this->self || $pl->user->can_view_lead($row, $forceShow)))
            $flags |= self::F_LEAD;
        if ($row->shepherdContactId == $this->contact->contactId
            && ($this->self || $pl->user->can_view_shepherd($row, $forceShow)))
            $flags |= self::F_SHEPHERD;
        return [$ranal, $flags];
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        $k = $sorter->uid;
        foreach ($rows as $row) {
            list($ranal, $flags) = $this->analysis($pl, $row, true);
            if ($ranal && $ranal->rrow->reviewType) {
                $row->$k = 16 * $ranal->rrow->reviewType;
                if ($ranal->rrow->reviewSubmitted)
                    $row->$k += 8;
            } else
                $row->$k = ($flags & self::F_CONFLICT ? -16 : 0);
            if ($flags & self::F_LEAD)
                $row->$k += 4;
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $k = $sorter->uid;
        return $b->$k - $a->$k;
    }
    function header(PaperList $pl, $is_text) {
        if ($this->self)
            return "Review";
        else if ($is_text)
            return $pl->user->name_text_for($this->contact) . " review";
        else
            return $pl->user->name_html_for($this->contact) . "<br />review";
    }
    function content(PaperList $pl, PaperInfo $row) {
        list($ranal, $flags) = $this->analysis($pl, $row);
        $t = "";
        if ($ranal)
            $t = $ranal->icon_html(true);
        else if ($flags & self::F_CONFLICT)
            $t = review_type_icon(-1);
        $x = null;
        if ($flags & self::F_LEAD)
            $x[] = review_lead_icon();
        if ($flags & self::F_SHEPHERD)
            $x[] = review_shepherd_icon();
        if ($x || ($ranal && $ranal->round)) {
            $c = ["pl_revtype"];
            $t && ($c[] = "hasrev");
            ($flags & (self::F_LEAD | self::F_SHEPHERD)) && ($c[] = "haslead");
            $ranal && $ranal->round && ($c[] = "hasround");
            $t && ($x[] = $t);
            return '<div class="' . join(" ", $c) . '">' . join('&nbsp;', $x) . '</div>';
        } else
            return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        list($ranal, $flags) = $this->analysis($pl, $row);
        $t = null;
        if ($flags & self::F_LEAD)
            $t[] = "Lead";
        if ($flags & self::F_SHEPHERD)
            $t[] = "Shepherd";
        if ($ranal)
            $t[] = $ranal->icon_text();
        if ($flags & self::F_CONFLICT)
            $t[] = "Conflict";
        return $t ? join("; ", $t) : "";
    }
}

class ReviewerType_PaperColumnFactory extends PaperColumnFactory {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function instantiate(Contact $user, $name, $errors) {
        $colon = strpos($name, ":");
        $x = ContactSearch::make_pc(substr($name, $colon + 1), $user)->ids;
        if (empty($x)) {
            self::instantiate_error($errors, "No PC member matches “" . htmlspecialchars(substr($name, $colon + 1)) . "”.", 2);
            return null;
        }
        foreach ($x as &$cid) {
            $u = $user->conf->pc_member_by_id($cid);
            $fname = substr($name, 0, $colon + 1) . $u->email;
            $cid = new ReviewerTypePaperColumn(["name" => $fname] + (array) PaperColumn::lookup_json("revtype"), $u);
        }
        return $x;
    }
}

class ReviewDelegation_PaperColumn extends PaperColumn {
    private $requester;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->isPC)
            return false;
        $pl->qopts["reviewSignatures"] = true;
        $this->requester = $pl->reviewer_user();
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Requested reviews";
    }
    function content(PaperList $pl, PaperInfo $row) {
        global $Now;
        $rx = [];
        $row->ensure_reviewer_names();
        foreach ($row->reviews_by_display() as $rrow) {
            if ($rrow->reviewType == REVIEW_EXTERNAL
                && $rrow->requestedBy == $this->requester->contactId) {
                if (!$pl->user->can_view_review($row, $rrow, true))
                    continue;
                if ($pl->user->can_view_review_identity($row, $rrow, true))
                    $t = $pl->user->reviewer_html_for($rrow);
                else
                    $t = "review";
                $ranal = $pl->make_review_analysis($rrow, $row);
                $description = $ranal->description_text();
                if ($rrow->reviewOrdinal)
                    $description = rtrim("#" . unparseReviewOrdinal($rrow) . " " . $description);
                $description = $ranal->wrap_link($description, "uu");
                if (!$rrow->reviewSubmitted && $rrow->reviewNeedsSubmit >= 0)
                    $description = '<strong class="overdue">' . $description . '</strong>';
                $t .= ", $description";
                if (!$rrow->reviewSubmitted) {
                    $pl->mark_has("need_review");
                    $row->ensure_reviewer_last_login();
                    if (!$rrow->reviewLastLogin)
                        $t .= ' <span class="hint">(never logged in)</span>';
                    else if ($rrow->reviewLastLogin >= $Now - 259200)
                        $t .= ' <span class="hint">(last site activity ' . plural(round(($Now - $rrow->reviewLastLogin) / 3600), "hour") . ' ago)</span>';
                    else
                        $t .= ' <span class="hint">(last site activity ' . plural(round(($Now - $rrow->reviewLastLogin) / 86400), "day") . ' ago)</span>';
                }
                $rx[] = $t;
            }
        }
        return join('; ', $rx);
    }
}

class AssignReviewPaperColumn extends ReviewerTypePaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $this->contact ? : $pl->reviewer_user();
        if (!$pl->user->is_manager())
            return false;
        if ($visible > 0 && ($tid = $pl->table_id()))
            $pl->add_header_script("add_assrev_ajax(" . json_encode_browser("#$tid") . ")");
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Assignment";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->allow_administer($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $ci = $row->contact_info($this->contact);
        if ($ci->conflictType >= CONFLICT_AUTHOR)
            return '<span class="author">Author</span>';
        if ($ci->conflictType > 0)
            $rt = -1;
        else
            $rt = min(max($ci->reviewType, 0), REVIEW_META);
        if ($this->contact->can_accept_review_assignment_ignore_conflict($row)
            || $rt > 0)
            $options = array(0 => "None",
                             REVIEW_PRIMARY => "Primary",
                             REVIEW_SECONDARY => "Secondary",
                             REVIEW_PC => "Optional",
                             REVIEW_META => "Metareview",
                             -1 => "Conflict");
        else
            $options = array(0 => "None", -1 => "Conflict");
        return Ht::select("assrev{$row->paperId}u{$this->contact->contactId}",
                          $options, $rt, ["tabindex" => 3]);
    }
}

class Desirability_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->privChair)
            return false;
        if ($visible)
            $pl->qopts["desirability"] = 1;
        return true;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        return $b->desirability < $a->desirability ? -1 : ($b->desirability > $a->desirability ? 1 : 0);
    }
    function header(PaperList $pl, $is_text) {
        return "Desirability";
    }
    function content(PaperList $pl, PaperInfo $row) {
        return htmlspecialchars($this->text($pl, $row));
    }
    function text(PaperList $pl, PaperInfo $row) {
        return get($row, "desirability") + 0;
    }
}

class TopicScorePaperColumn extends PaperColumn {
    private $contact;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $pl->reviewer_user();
        if (!$pl->conf->has_topics()
            || !$pl->user->isPC
            || ($this->contact->contactId !== $pl->user->contactId
                && !$pl->user->is_manager()))
            return false;
        if ($visible)
            $pl->qopts["topics"] = 1;
        return true;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        return $b->topic_interest_score($this->contact) - $a->topic_interest_score($this->contact);
    }
    function header(PaperList $pl, $is_text) {
        return $is_text ? "Topic score" : "Topic<br />score";
    }
    function content(PaperList $pl, PaperInfo $row) {
        return htmlspecialchars($row->topic_interest_score($this->contact));
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->topic_interest_score($this->contact);
    }
}

class PreferencePaperColumn extends PaperColumn {
    private $editable;
    private $contact;
    private $viewer_contact;
    private $not_me;
    private $show_conflict;
    private $prefix;
    function __construct($cj, $contact = null) {
        parent::__construct($cj);
        $this->editable = !!get($cj, "edit");
        $this->contact = $contact;
    }
    function make_editable() {
        return new PreferencePaperColumn(["name" => $this->name] + (array) self::lookup_json("editpref"), $this->contact);
    }
    function prepare(PaperList $pl, $visible) {
        $this->viewer_contact = $pl->user;
        $reviewer = $pl->reviewer_user();
        $this->contact = $this->contact ? : $reviewer;
        $this->not_me = $this->contact->contactId !== $pl->user->contactId;
        if (!$pl->user->isPC
            || (($this->not_me || !$this->name /* user factory */)
                && !$pl->user->is_manager()))
            return false;
        if ($visible)
            $pl->qopts["topics"] = 1;
        if ($this->editable && $visible > 0 && ($tid = $pl->table_id()))
            $pl->add_header_script("add_revpref_ajax(" . json_encode_browser("#$tid") . ")", "revpref_ajax_$tid");
        $this->prefix =  "";
        if ($this->row)
            $this->prefix = $pl->user->reviewer_html_for($this->contact);
        return true;
    }
    function completion_name() {
        return $this->name ? : "pref:<user>";
    }
    function sort_name($score_sort) {
        return "pref";
    }
    private function preference_values($row) {
        if ($this->not_me && !$this->viewer_contact->allow_administer($row))
            return [null, null];
        else
            return $row->reviewer_preference($this->contact);
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        list($ap, $ae) = $this->preference_values($a);
        list($bp, $be) = $this->preference_values($b);
        if ($ap === null || $bp === null)
            return $ap === $bp ? 0 : ($ap === null ? 1 : -1);
        if ($ap != $bp)
            return $ap < $bp ? 1 : -1;

        if ($ae !== $be) {
            if (($ae === null) !== ($be === null))
                return $ae === null ? 1 : -1;
            return (float) $ae < (float) $be ? 1 : -1;
        }

        $at = $a->topic_interest_score($this->contact);
        $bt = $b->topic_interest_score($this->contact);
        if ($at != $bt)
            return $at < $bt ? 1 : -1;
        return 0;
    }
    function analyze(PaperList $pl, &$rows, $fields) {
        $this->show_conflict = true;
        foreach ($fields as $fdef)
            if ($fdef instanceof ReviewerTypePaperColumn
                && $fdef->is_visible
                && $fdef->contact()->contactId == $this->contact->contactId)
                $this->show_conflict = false;
    }
    function header(PaperList $pl, $is_text) {
        if (!$this->not_me || $this->row)
            return "Preference";
        else if ($is_text)
            return $pl->user->name_text_for($this->contact) . " preference";
        else
            return $pl->user->name_html_for($this->contact) . "<br />preference";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return $this->not_me && !$pl->user->allow_administer($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $has_cflt = $row->has_conflict($this->contact);
        $pv = $this->preference_values($row);
        $ptext = unparse_preference($pv);
        $editable = $this->editable && $this->contact->can_become_reviewer_ignore_conflict($row);
        if (!$editable)
            $ptext = str_replace("-", "−" /* U+2122 */, $ptext);
        $conflict_wrap = $this->not_me && !$pl->user->can_administer($row, false);
        if ($this->row) {
            if ($ptext !== "")
                $ptext = $this->prefix . " <span class=\"asspref" . ($pv[0] < 0 ? "-1" : "1") . "\">P" . $ptext . "</span>";
            return $pl->maybeConflict($row, $ptext, !$conflict_wrap);
        } else if ($has_cflt && !$pl->user->allow_administer($row))
            return $this->show_conflict ? review_type_icon(-1) : "";
        else if ($editable) {
            $iname = "revpref" . $row->paperId;
            if ($this->not_me)
                $iname .= "u" . $this->contact->contactId;
            return '<input name="' . $iname . '" class="revpref" value="' . ($ptext !== "0" ? $ptext : "") . '" type="text" size="4" tabindex="2" placeholder="0" />' . ($this->show_conflict && $has_cflt ? "&nbsp;" . review_type_icon(-1) : "");
        } else {
            if ($conflict_wrap)
                $ptext = '<span class="fx5">' . $ptext . '</span><span class="fn5">?</span>';
            return $ptext;
        }
    }
    function text(PaperList $pl, PaperInfo $row) {
        return unparse_preference($this->preference_values($row));
    }
}

class Preference_PaperColumnFactory extends PaperColumnFactory {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function instantiate(Contact $user, $name, $errors) {
        $opts = (array) PaperColumn::lookup_json("pref");
        if (str_ends_with($name, ":row")) {
            $opts["row"] = true;
            $opts["column"] = false;
            $name = substr($name, 0, -4);
        }
        $colon = strpos($name, ":");
        $x = ContactSearch::make_pc(substr($name, $colon + 1), $user)->ids;
        if (empty($x)) {
            self::instantiate_error($errors, "No PC member matches “" . htmlspecialchars(substr($name, $colon + 1)) . "”.", 2);
            return null;
        }
        foreach ($x as &$cid) {
            $u = $user->conf->pc_member_by_id($cid);
            $fname = substr($name, 0, $colon + 1) . $u->email;
            $cid = new PreferencePaperColumn(["name" => $fname] + $opts, $u);
        }
        return $x;
    }
}

class PreferenceList_PaperColumn extends PaperColumn {
    private $topics;
    function __construct($cj) {
        parent::__construct($cj);
        $this->topics = get($cj, "topics");
    }
    function prepare(PaperList $pl, $visible) {
        if ($this->topics && !$pl->conf->has_topics())
            $this->topics = false;
        if (!$pl->user->is_manager())
            return false;
        if ($visible) {
            $pl->qopts["allReviewerPreference"] = $pl->qopts["allConflictType"] = 1;
            if ($this->topics)
                $pl->qopts["topics"] = 1;
        }
        $pl->conf->stash_hotcrp_pc($pl->user);
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Preferences";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->allow_administer($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $prefs = $row->reviewer_preferences();
        $ts = array();
        if ($prefs || $this->topics)
            foreach ($row->conf->pc_members() as $pcid => $pc) {
                if (($pref = get($prefs, $pcid))
                    && ($pref[0] !== 0 || $pref[1] !== null)) {
                    $t = "P" . $pref[0];
                    if ($pref[1] !== null)
                        $t .= unparse_expertise($pref[1]);
                    $ts[] = $pcid . $t;
                } else if ($this->topics
                           && ($tscore = $row->topic_interest_score($pc)))
                    $ts[] = $pcid . "T" . $tscore;
            }
        $pl->row_attr["data-allpref"] = join(" ", $ts);
        if (!empty($ts)) {
            $t = '<span class="need-allpref">Loading</span>';
            $pl->need_render = true;
            return $t;
        } else
            return '';
    }
}

class ReviewerList_PaperColumn extends PaperColumn {
    private $topics;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_some_review_identity(null))
            return false;
        $this->topics = $pl->conf->has_topics();
        $pl->qopts["reviewSignatures"] = true;
        if ($visible && $pl->user->privChair)
            $pl->qopts["allReviewerPreference"] = $pl->qopts["topics"] = true;
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Reviewers";
    }
    private function reviews_with_names(PaperInfo $row) {
        $row->ensure_reviewer_names();
        $rrows = $row->reviews_by_id();
        foreach ($rrows as $rrow)
            Contact::set_sorter($rrow, $row->conf);
        usort($rrows, "Contact::compare");
        return $rrows;
    }
    function content(PaperList $pl, PaperInfo $row) {
        // see also search.php > getaction == "reviewers"
        $x = [];
        foreach ($this->reviews_with_names($row) as $xrow)
            if ($pl->user->can_view_review_identity($row, $xrow, true)) {
                $ranal = $pl->make_review_analysis($xrow, $row);
                $n = $pl->user->reviewer_html_for($xrow) . "&nbsp;" . $ranal->icon_html(false);
                if ($pl->user->privChair) {
                    $pref = $row->reviewer_preference((int) $xrow->contactId);
                    if ($this->topics && $row->has_topics())
                        $pref[2] = $row->topic_interest_score((int) $xrow->contactId);
                    $n .= unparse_preference_span($pref);
                }
                $x[] = '<span class="nw">' . $n . '</span>';
            }
        if (empty($x))
            return "";
        else
            return $pl->maybeConflict($row, join(", ", $x), $pl->user->can_view_review_identity($row, null, false));
    }
    function text(PaperList $pl, PaperInfo $row) {
        if (!$pl->user->can_view_review_identity($row, null))
            return "";
        $x = [];
        foreach ($this->reviews_with_names($row) as $xrow)
            if ($pl->user->can_view_review_identity($row, $xrow))
                $x[] = $pl->user->name_text_for($xrow);
        return join("; ", $x);
    }
}

class PCConflictListPaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->privChair)
            return false;
        if ($visible)
            $pl->qopts["allConflictType"] = 1;
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "PC conflicts";
    }
    function content(PaperList $pl, PaperInfo $row) {
        $y = [];
        $pcm = $row->conf->pc_members();
        foreach ($row->conflicts() as $id => $type)
            if (($pc = get($pcm, $id)))
                $y[$pc->sort_position] = $pl->user->reviewer_html_for($pc);
        ksort($y);
        return join(", ", $y);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $y = [];
        $pcm = $row->conf->pc_members();
        foreach ($row->conflicts() as $id => $type)
            if (($pc = get($pcm, $id)))
                $y[$pc->sort_position] = $pl->user->name_text_for($pc);
        ksort($y);
        return join("; ", $y);
    }
}

class ConflictMatchPaperColumn extends PaperColumn {
    private $field;
    private $highlight;
    function __construct($cj) {
        parent::__construct($cj);
        if ($cj->name === "authorsmatch")
            $this->field = "authorInformation";
        else
            $this->field = "collaborators";
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $pl->reviewer_user();
        $this->highlight = $pl->search->field_highlighter($this->field);
        $general_pregexes = $this->contact->aucollab_general_pregexes();
        return $pl->user->is_manager() && !empty($general_pregexes);
    }
    function header(PaperList $pl, $is_text) {
        $what = $this->field == "authorInformation" ? "authors" : "collaborators";
        if ($is_text)
            return "Potential conflict in $what";
        else
            return "<strong>Potential conflict in $what</strong>";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->allow_administer($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $field = $this->field;
        if (!$row->field_match_pregexes($this->contact->aucollab_general_pregexes(), $field))
            return "";
        $text = [];
        $aus = $field === "collaborators" ? $row->collaborator_list() : $row->author_list();
        foreach ($aus as $au) {
            $matchers = [];
            foreach ($this->contact->aucollab_matchers() as $matcher)
                if ($matcher->test($au))
                    $matchers[] = $matcher;
            if (!empty($matchers))
                $text[] = PaperInfo_AuthorMatcher::highlight_all($au, $matchers);
        }
        if (!empty($text))
            unset($row->folded);
        return join("; ", $text);
    }
}

class TagList_PaperColumn extends PaperColumn {
    private $editable;
    function __construct($cj, $editable = false) {
        parent::__construct($cj);
        $this->editable = $editable;
    }
    function make_editable() {
        return new TagList_PaperColumn($this->column_json(), true);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_tags(null))
            return false;
        if ($visible)
            $pl->qopts["tags"] = 1;
        if ($visible && $this->editable && ($tid = $pl->table_id()))
            $pl->add_header_script("plinfo_tags(" . json_encode_browser("#$tid") . ")", "plinfo_tags");
        if ($this->editable)
            $pl->has_editable_tags = true;
        return true;
    }
    function annotate_field_js(PaperList $pl, &$fjs) {
        $fjs["highlight_tags"] = $pl->search->highlight_tags();
        if ($pl->conf->tags()->has_votish)
            $fjs["votish_tags"] = array_values(array_map(function ($t) { return $t->tag; }, $pl->conf->tags()->filter("votish")));
    }
    function header(PaperList $pl, $is_text) {
        return "Tags";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_tags($row, true);
    }
    function content(PaperList $pl, PaperInfo $row) {
        if ($row->paperTags && $row->conflictType > 0 && $pl->user->allow_administer($row)) {
            $viewable = trim($row->viewable_tags($pl->user, true));
            $pl->row_attr["data-tags-conflicted"] = trim($row->viewable_tags($pl->user, false));
        } else
            $viewable = trim($row->viewable_tags($pl->user));
        $pl->row_attr["data-tags"] = $viewable;
        if ($this->editable)
            $pl->row_attr["data-tags-editable"] = 1;
        if ($viewable !== "" || $this->editable) {
            $pl->need_render = true;
            return '<span class="need-tags"></span>';
        } else
            return "";
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->tagger->unparse_hashed($row->viewable_tags($pl->user));
    }
}

class Tag_PaperColumn extends PaperColumn {
    protected $is_value;
    protected $dtag;
    protected $xtag;
    protected $ctag;
    protected $editable = false;
    protected $emoji = false;
    function __construct($cj, $tag) {
        parent::__construct($cj);
        $this->dtag = $tag;
        $this->is_value = get($cj, "tagvalue");
    }
    function make_editable() {
        $is_value = $this->is_value || $this->is_value === null;
        return new EditTag_PaperColumn($this->column_json() + ["tagvalue" => $is_value], $this->dtag);
    }
    function sorts_my_tag($sorter, Contact $user) {
        return strcasecmp(Tagger::check_tag_keyword($sorter->type, $user, Tagger::NOVALUE | Tagger::ALLOWCONTACTID), $this->xtag) == 0;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_tags(null))
            return false;
        $tagger = new Tagger($pl->user);
        if (!($ctag = $tagger->check($this->dtag, Tagger::NOVALUE | Tagger::ALLOWCONTACTID)))
            return false;
        $this->xtag = strtolower($ctag);
        $this->ctag = " {$this->xtag}#";
        if ($visible)
            $pl->qopts["tags"] = 1;
        $this->className = ($this->is_value ? "pl_tagval" : "pl_tag");
        if ($this->dtag[0] == ":" && !$this->is_value
            && ($dt = $pl->user->conf->tags()->check($this->dtag))
            && count($dt->emoji) == 1)
            $this->emoji = $dt->emoji[0];
        return true;
    }
    function completion_name() {
        return "#$this->dtag";
    }
    function sort_name($score_sort) {
        return "#$this->dtag";
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        $k = $sorter->uid;
        $careful = !$pl->user->privChair && !$pl->conf->tag_seeall;
        $unviewable = $empty = $sorter->reverse ? -(TAG_INDEXBOUND - 1) : TAG_INDEXBOUND - 1;
        if ($this->editable)
            $empty = $sorter->reverse ? -TAG_INDEXBOUND : TAG_INDEXBOUND;
        foreach ($rows as $row)
            if ($careful && !$pl->user->can_view_tag($row, $this->xtag, true))
                $row->$k = $unviewable;
            else if (($row->$k = $row->tag_value($this->xtag)) === false)
                $row->$k = $empty;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $k = $sorter->uid;
        return $a->$k < $b->$k ? -1 : ($a->$k == $b->$k ? 0 : 1);
    }
    function header(PaperList $pl, $is_text) {
        return "#$this->dtag";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_tag($row, $this->xtag, true);
    }
    function content(PaperList $pl, PaperInfo $row) {
        if (($v = $row->tag_value($this->xtag)) === false)
            return "";
        else if ($v >= 0.0 && $this->emoji)
            return Tagger::unparse_emoji_html($this->emoji, $v);
        else if ($v === 0.0 && !$this->is_value)
            return "✓";
        else
            return $v;
    }
    function text(PaperList $pl, PaperInfo $row) {
        if (($v = $row->tag_value($this->xtag)) === false)
            return "N";
        else if ($v === 0.0 && !$this->is_value)
            return "Y";
        else
            return $v;
    }
}

class Tag_PaperColumnFactory extends PaperColumnFactory {
    private $cj;
    function __construct($cj) {
        parent::__construct($cj);
        $this->cj = (array) $cj;
    }
    function instantiate(Contact $user, $name, $errors) {
        $p = str_starts_with($name, "#") ? 0 : strpos($name, ":");
        return new Tag_PaperColumn(["name" => $name] + $this->cj, substr($name, $p + 1));
    }
    function completion_name() {
        return "#<tag>";
    }
}

class EditTag_PaperColumn extends Tag_PaperColumn {
    private $editsort;
    function __construct($cj, $tag) {
        parent::__construct($cj, $tag);
        $this->editable = true;
    }
    function prepare(PaperList $pl, $visible) {
        $this->editsort = false;
        if (!parent::prepare($pl, $visible))
            return false;
        if ($visible > 0 && ($tid = $pl->table_id())) {
            $sorter = get($pl->sorters, 0);
            if ($this->sorts_my_tag($sorter, $pl->user)
                && !$sorter->reverse
                && (!$pl->search->thenmap || $pl->search->is_order_anno)
                && $this->is_value) {
                $this->editsort = true;
                $pl->tbody_attr["data-drag-tag"] = $this->dtag;
            }
            $pl->has_editable_tags = true;
            $pl->add_header_script("plinfo_tags(" . json_encode_browser("#$tid") . ")", "plinfo_tags");
        }
        $this->className = $this->is_value ? "pl_edittagval" : "pl_edittag";
        return true;
    }
    function content(PaperList $pl, PaperInfo $row) {
        $v = $row->tag_value($this->xtag);
        if ($this->editsort && !isset($pl->row_attr["data-tags"]))
            $pl->row_attr["data-tags"] = $this->dtag . "#" . $v;
        if (!$pl->user->can_change_tag($row, $this->dtag, 0, 0, true))
            return $this->is_value ? (string) $v : ($v === false ? "" : "&#x2713;");
        if (!$this->is_value)
            return '<input type="checkbox" class="cb edittag" name="tag:' . "$this->dtag $row->paperId" . '" value="x" tabindex="6"'
                . ($v !== false ? ' checked="checked"' : '') . " />";
        $t = '<input type="text" class="edittagval';
        if ($this->editsort) {
            $t .= " need-draghandle";
            $pl->need_render = true;
        }
        return $t . '" size="4" name="tag:' . "$this->dtag $row->paperId" . '" value="'
            . ($v !== false ? htmlspecialchars($v) : "") . '" tabindex="6" />';
    }
}

class ScoreGraph_PaperColumn extends PaperColumn {
    protected $contact;
    protected $not_me;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function sort_name($score_sort) {
        $score_sort = ListSorter::canonical_long_score_sort($score_sort);
        return $this->name . ($score_sort ? " $score_sort" : "");
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $pl->context_user();
        $this->not_me = $this->contact->contactId !== $pl->user->contactId;
        if ($visible && $this->not_me
            && (!$pl->user->privChair || $pl->conf->has_any_manager()))
            $pl->qopts["reviewSignatures"] = true;
    }
    function score_values(PaperList $pl, PaperInfo $row, $forceShow) {
        return null;
    }
    protected function set_sort_fields(PaperList $pl, PaperInfo $row, ListSorter $sorter) {
        $k = $sorter->uid;
        $avgk = $k . "avg";
        $s = $this->score_values($pl, $row, null);
        if ($s !== null) {
            $scoreinfo = new ScoreInfo($s, true);
            $cid = $this->contact->contactId;
            if ($this->not_me
                && !$row->can_view_review_identity_of($cid, $pl->user))
                $cid = 0;
            $row->$k = $scoreinfo->sort_data($sorter->score, $cid);
            $row->$avgk = $scoreinfo->mean();
        } else
            $row->$k = $row->$avgk = null;
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        foreach ($rows as $row)
            self::set_sort_fields($pl, $row, $sorter);
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $k = $sorter->uid;
        if (!($x = ScoreInfo::compare($b->$k, $a->$k, -1))) {
            $k .= "avg";
            $x = ScoreInfo::compare($b->$k, $a->$k);
        }
        return $x;
    }
    function field_content(PaperList $pl, ReviewField $field, PaperInfo $row) {
        $values = $this->score_values($pl, $row, false);
        $wrap_conflict = false;
        if (empty($values) && $row->conflictType > 0
            && $pl->user->allow_administer($row)) {
            $values = $this->score_values($pl, $row, true);
            $wrap_conflict = true;
        }
        if (empty($values))
            return "";
        $pl->need_render = true;
        $cid = $this->contact->contactId;
        if ($this->not_me && !$row->can_view_review_identity_of($cid, $pl->user))
            $cid = 0;
        $t = $field->unparse_graph($values, 1, get($values, $cid));
        return $wrap_conflict ? '<span class="fx5">' . $t . '</span>' : $t;
    }
}

class Score_PaperColumn extends ScoreGraph_PaperColumn {
    public $score;
    public $max_score;
    private $form_field;
    function __construct($cj, ReviewField $form_field) {
        parent::__construct(["name" => $form_field->search_keyword()] + (array) $cj);
        $this->score = $form_field->id;
        $this->form_field = $form_field;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->scoresOk
            || $this->form_field->view_score <= $pl->user->permissive_view_score_bound())
            return false;
        if ($visible) {
            $pl->qopts["scores"][$this->score] = true;
            $this->max_score = count($this->form_field->options);
        }
        parent::prepare($pl, $visible);
        return true;
    }
    function score_values(PaperList $pl, PaperInfo $row, $forceShow) {
        $fid = $this->form_field->id;
        $row->ensure_review_score($this->form_field);
        $scores = [];
        foreach ($row->viewable_submitted_reviews_by_user($pl->user, $forceShow) as $rrow)
            if (isset($rrow->$fid) && $rrow->$fid)
                $scores[$rrow->contactId] = $rrow->$fid;
        return $scores;
    }
    function header(PaperList $pl, $is_text) {
        return $is_text ? $this->form_field->search_keyword() : $this->form_field->web_abbreviation();
    }
    function alternate_display_name() {
        return $this->form_field->id;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        // Do not use score_values to determine content emptiness, since
        // that would load the scores from the DB -- even for folded score
        // columns.
        return !$row->may_have_viewable_scores($this->form_field, $pl->user, true);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return parent::field_content($pl, $this->form_field, $row);
    }
}

class Score_PaperColumnFactory extends PaperColumnFactory {
    private $cj;
    function __construct($cj) {
        parent::__construct($cj);
        $this->cj = (array) $cj;
    }
    function instantiate(Contact $user, $name, $errors) {
        if ($name === "scores") {
            $fs = $user->conf->all_review_fields();
            $errors && ($errors->allow_empty = true);
        } else
            $fs = [$user->conf->find_review_field($name)];
        $fs = array_filter($fs, function ($f) { return $f && $f->has_options && $f->displayed; });
        return array_map(function ($f) { return new Score_PaperColumn($this->cj, $f); }, $fs);
    }
    function completion_instances(Contact $user) {
        return array_merge([$this], $this->instantiate($user, "scores", null));
    }
    function completion_name() {
        return "scores";
    }
}

class FormulaGraph_PaperColumn extends ScoreGraph_PaperColumn {
    public $formula;
    private $indexes_function;
    private $formula_function;
    private $results;
    function __construct($cj, Formula $formula) {
        parent::__construct($cj);
        $this->formula = $formula;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->scoresOk
            || !$this->formula->check($pl->user)
            || !($this->formula->result_format() instanceof ReviewField)
            || !$pl->user->can_view_formula($this->formula, $pl->search->limitName == "a"))
            return false;
        $this->formula_function = $this->formula->compile_sortable_function();
        $this->indexes_function = null;
        if ($this->formula->is_indexed())
            $this->indexes_function = Formula::compile_indexes_function($pl->user, $this->formula->datatypes());
        if ($visible)
            $this->formula->add_query_options($pl->qopts);
        parent::prepare($pl, $visible);
        return true;
    }
    function score_values(PaperList $pl, PaperInfo $row, $forceShow) {
        $indexesf = $this->indexes_function;
        $indexes = $indexesf ? $indexesf($row, $pl->user, $forceShow) : [null];
        $formulaf = $this->formula_function;
        $vs = [];
        foreach ($indexes as $i)
            if (($v = $formulaf($row, $i, $pl->user, $forceShow)) !== null)
                $vs[$i] = $v;
        return $vs;
    }
    function header(PaperList $pl, $is_text) {
        $x = $this->formula->column_header();
        if ($is_text)
            return $x;
        else if ($this->formula->headingTitle && $this->formula->headingTitle != $x)
            return "<span class=\"need-tooltip\" data-tooltip=\"" . htmlspecialchars($this->formula->headingTitle) . "\">" . htmlspecialchars($x) . "</span>";
        else
            return htmlspecialchars($x);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return parent::field_content($pl, $this->formula->result_format(), $row);
    }
}

class FormulaGraph_PaperColumnFactory extends PaperColumnFactory {
    private $cj;
    static private $nregistered;
    function __construct($cj) {
        parent::__construct($cj);
        $this->cj = (array) $cj;
    }
    function instantiate(Contact $user, $name, $errors) {
        if (str_starts_with($name, "g("))
            $name = substr($name, 1);
        else if (str_starts_with($name, "graph("))
            $name = substr($name, 5);
        else
            return null;
        $formula = new Formula($name, true);
        if (!$formula->check($user)) {
            self::instantiate_error($errors, $formula->error_html(), 1);
            return null;
        } else if (!($formula->result_format() instanceof ReviewField)) {
            self::instantiate_error($errors, "Graphed formulas must return review fields.", 1);
            return null;
        }
        ++self::$nregistered;
        return new FormulaGraph_PaperColumn(["name" => "scorex" . self::$nregistered] + $this->cj, $formula);
    }
    function completion_name() {
        return "graph(<formula>)";
    }
}

class Option_PaperColumn extends PaperColumn {
    private $opt;
    function __construct(PaperOption $opt, $cj, $isrow) {
        $name = $opt->search_keyword() . ($isrow ? "-row" : "");
        $optcj = $opt->list_display($isrow);
        if ($optcj === true && $isrow)
            $optcj = ["row" => true];
        else if ($optcj === true)
            $optcj = ["column" => true, "className" => "pl_option"];
        parent::__construct(["name" => $name] + ($optcj ? : []) + $cj);
        $this->opt = $opt;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_some_paper_option($this->opt))
            return false;
        $pl->qopts["options"] = true;
        return true;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        return $this->opt->value_compare($a->option($this->opt->id),
                                         $b->option($this->opt->id));
    }
    function header(PaperList $pl, $is_text) {
        return $is_text ? $this->opt->name : htmlspecialchars($this->opt->name);
    }
    function completion_name() {
        return $this->opt->search_keyword();
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_paper_option($row, $this->opt, true);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $t = "";
        if (($ok = $pl->user->can_view_paper_option($row, $this->opt, false))
            || ($pl->user->allow_administer($row)
                && $pl->user->can_view_paper_option($row, $this->opt, true))) {
            $isrow = $this->viewable_row();
            $t = $this->opt->unparse_list_html($pl, $row, $isrow);
            if (!$ok && $t !== "") {
                if ($isrow)
                    $t = '<div class="fx5">' . $t . '</div>';
                else
                    $t = '<span class="fx5">' . $t . '</div>';
            }
        }
        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        if ($pl->user->can_view_paper_option($row, $this->opt))
            return $this->opt->unparse_list_text($pl, $row);
        return "";
    }
}

class Option_PaperColumnFactory extends PaperColumnFactory {
    private $cj;
    function __construct($cj) {
        parent::__construct($cj);
        $this->cj = (array) $cj;
    }
    private function all(Contact $user) {
        $x = [];
        foreach ($user->user_option_list() as $opt)
            if ($opt->display() >= 0)
                $x[] = new Option_PaperColumn($opt, $this->cj, false);
        return $x;
    }
    function instantiate(Contact $user, $name, $errors) {
        if ($name === "options") {
            $errors && ($errors->allow_empty = true);
            return $this->all($user);
        }
        $has_colon = false;
        if (str_starts_with($name, "opt:")) {
            $name = substr($name, 4);
            $has_colon = true;
        } else if (strpos($name, ":") !== false)
            return null;
        $isrow = false;
        if (str_ends_with($name, "-row")
            && ($opts = $user->conf->paper_opts->find_all(substr($name, 0, -4))))
            $isrow = true;
        else
            $opts = $user->conf->paper_opts->find_all($name);
        if (count($opts) == 1) {
            reset($opts);
            $opt = current($opts);
            if ($opt->list_display($isrow))
                return new Option_PaperColumn($opt, $this->cj, $isrow);
            self::instantiate_error($errors, "Option “" . htmlspecialchars($name) . "” can’t be displayed.", 1);
        } else if ($has_colon)
            self::instantiate_error($errors, "No such option “" . htmlspecialchars($name) . "”.", 1);
        return null;
    }
    function completion_instances(Contact $user) {
        return array_merge([$this], $this->all($user));
    }
    function completion_name() {
        return "options";
    }
}

class Formula_PaperColumn extends PaperColumn {
    public $formula;
    private $formula_function;
    private $statistics;
    private $override_statistics;
    private $results;
    private $override_results;
    private $real_format;
    function __construct($cj, Formula $formula) {
        parent::__construct($cj);
        $this->formula = $formula;
    }
    function completion_name() {
        if (strpos($this->formula->name, " ") !== false)
            return "\"{$this->formula->name}\"";
        else
            return $this->formula->name;
    }
    function sort_name($score_sort) {
        return $this->formula->name ? : $this->formula->expression;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->scoresOk
            || !$this->formula->check($pl->user)
            || !$pl->user->can_view_formula($this->formula, $pl->search->limitName == "a"))
            return false;
        $this->formula_function = $this->formula->compile_function();
        if ($visible)
            $this->formula->add_query_options($pl->qopts);
        return true;
    }
    function realize(PaperList $pl) {
        $f = clone $this;
        $f->statistics = new ScoreInfo;
        return $f;
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        $formulaf = $this->formula->compile_sortable_function();
        $k = $sorter->uid;
        foreach ($rows as $row)
            $row->$k = $formulaf($row, null, $pl->user);
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $k = $sorter->uid;
        $as = $a->$k;
        $bs = $b->$k;
        if ($as === null || $bs === null)
            return $as === $bs ? 0 : ($as === null ? -1 : 1);
        else
            return $as == $bs ? 0 : ($as < $bs ? -1 : 1);
    }
    function header(PaperList $pl, $is_text) {
        $x = $this->formula->column_header();
        if ($is_text)
            return $x;
        else if ($this->formula->headingTitle && $this->formula->headingTitle != $x)
            return "<span class=\"need-tooltip\" data-tooltip=\"" . htmlspecialchars($this->formula->headingTitle) . "\">" . htmlspecialchars($x) . "</span>";
        else
            return htmlspecialchars($x);
    }
    function analyze(PaperList $pl, &$rows, $fields) {
        if (!$this->is_visible)
            return;
        $formulaf = $this->formula_function;
        $this->results = $this->override_results = [];
        $this->real_format = null;
        $isreal = $this->formula->result_format_is_real();
        foreach ($rows as $row) {
            $v = $formulaf($row, null, $pl->user);
            $this->results[$row->paperId] = $v;
            if ($isreal && !$this->real_format && is_float($v)
                && round($v * 100) % 100 != 0)
                $this->real_format = "%.2f";
            if ($row->conflictType > 0 && $pl->user->allow_administer($row)) {
                $vv = $formulaf($row, null, $pl->user, true);
                if ($vv !== $v) {
                    $this->override_results[$row->paperId] = $vv;
                    if ($isreal && !$this->real_format && is_float($vv)
                        && round($vv * 100) % 100 != 0)
                        $this->real_format = "%.2f";
                }
            }
        }
        assert(!!$this->statistics);
    }
    private function unparse($x) {
        return $this->formula->unparse_html($x, $this->real_format);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $v = $this->results[$row->paperId];
        $t = $this->unparse($v);
        if (isset($this->override_results[$row->paperId])) {
            $vv = $this->override_results[$row->paperId];
            $tt = $this->unparse($vv);
            if (!$this->override_statistics)
                $this->override_statistics = clone $this->statistics;
            $this->override_statistics->add($vv);
            if ($t !== $tt)
                $t = '<span class="fn5">' . $t . '</span><span class="fx5">' . $tt . '</span>';
        }
        $this->statistics->add($v);
        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        $v = $this->results[$row->paperId];
        return $this->formula->unparse_text($v, $this->real_format);
    }
    function has_statistics() {
        return true;
    }
    private function unparse_stat($x, $stat) {
        if ($stat == ScoreInfo::MEAN || $stat == ScoreInfo::MEDIAN)
            return $this->unparse($x);
        else if ($stat == ScoreInfo::COUNT && is_int($x))
            return $x;
        else if ($this->real_format)
            return sprintf($this->real_format, $x);
        else
            return is_int($x) ? $x : sprintf("%.2f", $x);
    }
    function statistic($pl, $stat) {
        if ($stat == ScoreInfo::SUM && !$this->formula->result_format_is_real())
            return "";
        $t = $this->unparse_stat($this->statistics->statistic($stat), $stat);
        if ($this->override_statistics) {
            $tt = $this->unparse_stat($this->override_statistics->statistic($stat), $stat);
            if ($t !== $tt)
                $t = '<span class="fn5">' . $t . '</span><span class="fx5">' . $tt . '</span>';
        }
        return $t;
    }
}

class Formula_PaperColumnFactory extends PaperColumnFactory {
    private $cj;
    function __construct($cj) {
        parent::__construct($cj);
        $this->cj = (array) $cj;
    }
    private function make(Formula $f) {
        if ($f->formulaId)
            $name = $f->name;
        else
            $name = "formula:" . $f->expression;
        return new Formula_PaperColumn(["name" => $name] + $this->cj, $f);
    }
    private function all(Contact $user) {
        return array_map(function ($f) {
            return $this->make($f);
        }, $user->conf->named_formulas());
    }
    function instantiate(Contact $user, $name, $errors) {
        if ($name === "formulas")
            return $this->all($user);
        $ff = $user->conf->find_named_formula($name);
        if (!$ff && str_starts_with($name, "formula"))
            $ff = get($user->conf->named_formulas(), substr($name, 7));
        $ff = $ff ? : new Formula($name);
        if (!$ff->check($user)) {
            if ($errors && strpos($name, "(") !== false)
                self::instantiate_error($errors, $ff->error_html(), 1);
            return null;
        }
        return $this->make($ff);
    }
    function completion_name() {
        return "(<formula>)";
    }
    function completion_instances(Contact $user) {
        return array_merge([$this], $this->all($user));
    }
}

class TagReport_PaperColumn extends PaperColumn {
    private $tag;
    private $viewtype;
    function __construct($tag, $cj) {
        parent::__construct(["name" => "tagrep:" . strtolower($tag)] + $cj);
        $this->tag = $tag;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_any_peruser_tags($this->tag))
            return false;
        if ($visible)
            $pl->qopts["tags"] = 1;
        $dt = $pl->conf->tags()->check($this->tag);
        if (!$dt || $dt->rank || (!$dt->vote && !$dt->approval))
            $this->viewtype = 0;
        else
            $this->viewtype = $dt->approval ? 1 : 2;
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "#~" . $this->tag . " reports";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_peruser_tags($row, $this->tag, true);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $a = [];
        preg_match_all('/ (\d+)~' . preg_quote($this->tag) . '#(\S+)/i', $row->all_tags_text(), $m);
        for ($i = 0; $i != count($m[0]); ++$i) {
            if ($this->viewtype == 2 && $m[2][$i] <= 0)
                continue;
            $n = $pl->user->name_html_for($m[1][$i]);
            if ($this->viewtype != 1)
                $n .= " (" . $m[2][$i] . ")";
            $a[$m[1][$i]] = $n;
        }
        if (empty($a))
            return "";
        $pl->user->ksort_cid_array($a);
        $str = '<span class="nb">' . join(',</span> <span class="nb">', $a) . '</span>';
        return $pl->maybeConflict($row, $str, $row->conflictType <= 0 || $pl->user->can_view_peruser_tags($row, $this->tag, false));
    }
}

class TagReport_PaperColumnFactory extends PaperColumnFactory {
    private $cj;
    function __construct($cj) {
        parent::__construct($cj);
        $this->cj = (array) $cj;
    }
    function instantiate(Contact $user, $name, $errors) {
        if (!$user->can_view_most_tags())
            return null;
        $tagset = $user->conf->tags();
        if (str_starts_with($name, "tagrep:"))
            $tag = substr($name, 7);
        else if (str_starts_with($name, "tagreport:"))
            $tag = substr($name, 10);
        else if ($name === "tagreports") {
            $errors && ($errors->allow_empty = true);
            return array_map(function ($t) { return new TagReport_PaperColumn($t->tag, $this->cj); },
                             $tagset->filter_by(function ($t) { return $t->vote || $t->approval || $t->rank; }));
        } else
            return null;
        $t = $tagset->check($tag);
        if ($t && ($t->vote || $t->approval || $t->rank))
            return new TagReport_PaperColumn($tag, $this->cj);
        return null;
    }
}

class TimestampPaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $at = max($a->timeFinalSubmitted, $a->timeSubmitted, 0);
        $bt = max($b->timeFinalSubmitted, $b->timeSubmitted, 0);
        return $at > $bt ? -1 : ($at == $bt ? 0 : 1);
    }
    function header(PaperList $pl, $is_text) {
        return "Timestamp";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return max($row->timeFinalSubmitted, $row->timeSubmitted) <= 0;
    }
    function content(PaperList $pl, PaperInfo $row) {
        if (($t = max($row->timeFinalSubmitted, $row->timeSubmitted, 0)) > 0)
            return $row->conf->unparse_time_full($t);
        return "";
    }
}

class NumericOrderPaperColumn extends PaperColumn {
    private $order;
    function __construct($order) {
        parent::__construct([
            "name" => "numericorder", "sort" => true
        ]);
        $this->order = $order;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        return +get($this->order, $a->paperId) - +get($this->order, $b->paperId);
    }
}

class Lead_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->user->can_view_lead(null, true)
            && ($pl->conf->has_any_lead_or_shepherd() || $visible);
    }
    function header(PaperList $pl, $is_text) {
        return "Discussion lead";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$row->leadContactId
            || !$pl->user->can_view_lead($row, true);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $viewable = $pl->user->can_view_lead($row, null);
        return $pl->_contentPC($row, $row->leadContactId, $viewable);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $viewable = $pl->user->can_view_lead($row, null);
        return $pl->_textPC($row, $row->leadContactId, $viewable);
    }
}

class Shepherd_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->user->can_view_shepherd(null, true)
            && ($pl->conf->has_any_lead_or_shepherd() || $visible);
    }
    function header(PaperList $pl, $is_text) {
        return "Shepherd";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$row->shepherdContactId
            || !$pl->user->can_view_shepherd($row, true);
        // XXX external reviewer can view shepherd even if external reviewer
        // cannot view reviewer identities? WHO GIVES A SHIT
    }
    function content(PaperList $pl, PaperInfo $row) {
        $viewable = $pl->user->can_view_shepherd($row, null);
        return $pl->_contentPC($row, $row->shepherdContactId, $viewable);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $viewable = $pl->user->can_view_shepherd($row, null);
        return $pl->_textPC($row, $row->shepherdContactId, $viewable);
    }
}

class Administrator_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->user->can_view_manager(null);
    }
    function header(PaperList $pl, $is_text) {
        return "Administrator";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$row->managerContactId
            || !$pl->user->can_view_manager($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $pl->_contentPC($row, $row->managerContactId, true);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->_textPC($row, $row->managerContactId, true);
    }
}

class FoldAll_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        $pl->qopts["foldall"] = true;
        return true;
    }
}

class PageCount_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->user->can_view_some_pdf();
    }
    function page_count(Contact $user, PaperInfo $row) {
        if (!$user->can_view_pdf($row))
            return null;
        $dtype = DTYPE_SUBMISSION;
        if ($row->finalPaperStorageId > 0 && $row->outcome > 0
            && $user->can_view_decision($row, null))
            $dtype = DTYPE_FINAL;
        $doc = $row->document($dtype);
        return $doc ? $doc->npages() : null;
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        foreach ($rows as $row)
            $row->_page_count_sort_info = $this->page_count($pl->user, $row);
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $ac = $a->_page_count_sort_info;
        $bc = $b->_page_count_sort_info;
        if ($ac === null || $bc === null)
            return $ac === $bc ? 0 : ($ac === null ? -1 : 1);
        else
            return $ac == $bc ? 0 : ($ac < $bc ? -1 : 1);
    }
    function header(PaperList $pl, $is_text) {
        return "Page count";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_pdf($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return (string) $this->page_count($pl->user, $row);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return (string) $this->page_count($pl->user, $row);
    }
}

class Commenters_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function header(PaperList $pl, $is_text) {
        return "Commenters";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$row->viewable_comments($pl->user, null);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $crows = $row->viewable_comments($pl->user, null);
        $cnames = array_map(function ($cx) use ($pl) {
            $n = $t = $cx[0]->unparse_user_html($pl->user, null);
            if (($tags = $cx[0]->viewable_tags($pl->user, null))
                && ($color = $cx[0]->conf->tags()->color_classes($tags))) {
                $t = '<span class="cmtlink';
                if (TagInfo::classes_have_colors($color))
                    $t .= " tagcolorspan";
                $t .= " $color taghl\">" . $n . "</span>";
            }
            if ($cx[1] > 1)
                $t .= " ({$cx[1]})";
            return $t . $cx[2];
        }, CommentInfo::group_by_identity($crows, $pl->user, true));
        return join(" ", $cnames);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $crows = $row->viewable_comments($pl->user, null);
        $cnames = array_map(function ($cx) use ($pl) {
            $t = $cx[0]->unparse_user_text($pl->user, null);
            if ($cx[1] > 1)
                $t .= " ({$cx[1]})";
            return $t . $cx[2];
        }, CommentInfo::group_by_identity($crows, $pl->user, false));
        return join(" ", $cnames);
    }
}
