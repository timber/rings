<?php

declare(strict_types=1);

namespace Timber\Rings\Twig;

use WP_HTML_Tag_Processor;

/**
 * Tag processor that also tells where the current tag starts in the HTML.
 */
final class HtmlTagScanner extends WP_HTML_Tag_Processor
{
    public function get_current_tag_offset(): ?int
    {
        if (!$this->set_bookmark('current')) {
            return null;
        }

        // Bookmarks are WordPress internals: if they change, tags just don't get annotated
        $offset = $this->bookmarks['current']->start ?? null;
        $this->release_bookmark('current');

        return \is_int($offset) ? $offset : null;
    }
}
