<?php

/*
|--------------------------------------------------------------------------
| cleanRichText($html) - the server half of assets/sanitize-html.js
|--------------------------------------------------------------------------
|
| Post captions and phase descriptions are rich text from the editors. The
| pages scrub them before innerHTML; this scrubs them again when they are
| written (audit U-02, U-03), so a reader that forgets - a new page, an
| export, an email - still gets nothing that runs.
|
| The same allow-list as the browser: b, strong, i, em, u, ul, ol, li, a, br,
| p and div; text alignment; dir; links to http:, https: and mailto: (or a
| relative link). Other elements are unwrapped to their text; script and
| style go with their content.
*/

const RICH_TEXT_TAGS = ["b", "strong", "i", "em", "u", "ul", "ol", "li", "a", "br", "p", "div"];
const RICH_TEXT_ALIGNMENTS = ["left", "center", "right", "justify"];
const RICH_TEXT_DIRECTIONS = ["ltr", "rtl", "auto"];

/**
 * True for an href a browser would open as http(s), mailto, or a relative
 * link. The URL parser ignores ASCII whitespace and control characters
 * inside a scheme, so they are dropped before the scheme is read (U-05).
 */
function richTextHrefAllowed($href)
{
    $squeezed = preg_replace('/[\x00-\x20\x7F]+/', "", (string) $href);
    if (!preg_match('/^([a-z][a-z0-9+.\-]*):/i', $squeezed, $m)) {
        return true;
    }
    return in_array(strtolower($m[1]), ["http", "https", "mailto"], true);
}

function cleanRichText($html)
{
    $html = (string) $html;
    if (trim($html) === "") {
        return $html;
    }

    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    // The XML declaration makes libxml read the input as UTF-8 (Arabic captions).
    $doc->loadHTML('<?xml encoding="UTF-8"?><div id="rich-text-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = null;
    foreach ($doc->getElementsByTagName("div") as $div) {
        if ($div->getAttribute("id") === "rich-text-root") {
            $root = $div;
            break;
        }
    }
    if ($root === null) {
        return htmlspecialchars(strip_tags($html), ENT_QUOTES, "UTF-8");
    }

    cleanRichTextChildren($root);

    $out = "";
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

function cleanRichTextChildren(DOMNode $parent)
{
    // A static copy: unwrapping changes the list while it is walked.
    $children = [];
    foreach ($parent->childNodes as $child) {
        $children[] = $child;
    }

    foreach ($children as $node) {
        if ($node instanceof DOMText) {
            continue;
        }
        if (!($node instanceof DOMElement)) {
            // Comments, processing instructions, CDATA.
            $parent->removeChild($node);
            continue;
        }

        $name = strtolower($node->localName);
        if (in_array($name, ["script", "style", "template", "noscript", "iframe", "object", "embed", "textarea", "title", "xmp", "noembed", "noframes"], true)) {
            $parent->removeChild($node);
            continue;
        }

        cleanRichTextChildren($node);

        if (!in_array($name, RICH_TEXT_TAGS, true)) {
            while ($node->firstChild) {
                $parent->insertBefore($node->firstChild, $node);
            }
            $parent->removeChild($node);
            continue;
        }

        $align = "";
        if (preg_match('/text-align\s*:\s*([a-z]+)/i', $node->getAttribute("style"), $m)) {
            $align = strtolower($m[1]);
        } elseif ($node->hasAttribute("align")) {
            $align = strtolower(trim($node->getAttribute("align")));
        }
        $dir = strtolower(trim($node->getAttribute("dir")));
        $href = $node->hasAttribute("href") ? $node->getAttribute("href") : null;

        while ($node->attributes->length > 0) {
            $node->removeAttribute($node->attributes->item(0)->nodeName);
        }

        if (in_array($align, RICH_TEXT_ALIGNMENTS, true)) {
            $node->setAttribute("style", "text-align: $align;");
        }
        if (in_array($dir, RICH_TEXT_DIRECTIONS, true)) {
            $node->setAttribute("dir", $dir);
        }
        if ($name === "a") {
            if ($href !== null && richTextHrefAllowed($href)) {
                $node->setAttribute("href", $href);
            }
            $node->setAttribute("target", "_blank");
            $node->setAttribute("rel", "noopener noreferrer");
        }
    }
}
