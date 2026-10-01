<?php
declare(strict_types=1);

namespace TripleR\Support;

/**
 * Splits an already-loaded list into pages for display. The page number comes
 * from the query string, so paging works without scripts and keeps any filters.
 */
final class Pager
{
    public readonly int $page;
    public readonly int $pages;
    public readonly int $total;
    /** @var list<mixed> */
    public readonly array $rows;

    public function __construct(array $rows, int $perPage = 25, string $param = 'page')
    {
        $this->total = count($rows);
        $this->pages = max(1, (int) ceil($this->total / $perPage));
        $requested = filter_var($_GET[$param] ?? 1, FILTER_VALIDATE_INT);
        $this->page = min($this->pages, max(1, $requested === false ? 1 : (int) $requested));
        $this->rows = array_slice(array_values($rows), ($this->page - 1) * $perPage, $perPage);
        $this->param = $param;
        $this->perPage = $perPage;
    }

    private readonly string $param;
    private readonly int $perPage;

    public function first(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function last(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }

    public function url(int $page): string
    {
        $query = $_GET;
        $query[$this->param] = $page;
        return View::currentPath() . '?' . http_build_query($query);
    }

    /** "1–25 of 60" summary plus previous/next links; empty when everything fits on one page. */
    public function render(string $noun = 'record'): string
    {
        if ($this->pages <= 1) {
            return '';
        }
        $html = '<nav class="pager" aria-label="Pages"><span class="pager-summary">' . $this->first() . '–' . $this->last() . ' of ' . Format::plural($this->total, $noun) . '</span><span class="pager-links">';
        $html .= $this->page > 1
            ? '<a class="button button-secondary button-small" href="' . View::e($this->url($this->page - 1)) . '" rel="prev">Previous</a>'
            : '<span class="button button-secondary button-small is-disabled" aria-disabled="true">Previous</span>';
        $html .= '<span class="pager-position">Page ' . $this->page . ' of ' . $this->pages . '</span>';
        $html .= $this->page < $this->pages
            ? '<a class="button button-secondary button-small" href="' . View::e($this->url($this->page + 1)) . '" rel="next">Next</a>'
            : '<span class="button button-secondary button-small is-disabled" aria-disabled="true">Next</span>';
        return $html . '</span></nav>';
    }
}
