<?php
/* Allowlist HTML cleaner for the profile notes box (Quill editor on
 * /profile). Everything the browser sends is treated as untrusted: the
 * notes are re-rendered raw into the editor on the next page load, so
 * whatever survives this function is what reaches the DOM.
 *
 * Built on DOMDocument, no library (the app has no Composer). The rules
 * follow what Quill 2's toolbar on that page can actually produce:
 *   - tags: p br strong b em i u s h2 h3 ol ul li blockquote a span
 *     (anything else is unwrapped -- its text kept, the tag dropped --
 *     except script/style/iframe/... which go with their content);
 *   - li[data-list] = bullet|ordered (Quill 2 renders both list kinds as
 *     <ol> and tells them apart only by this attribute);
 *   - class tokens ql-align-center|right|justify and ql-indent-1..8;
 *   - style: only color / background-color with a #hex or rgb()/rgba()
 *     value (Quill's colour picker);
 *   - a[href]: only http(s), mailto and tel; always target=_blank with
 *     rel="noopener noreferrer";
 *   - every other attribute (on*, style beyond the above, id, src, ...) is
 *     removed.
 * Quill's own UI marker spans (span.ql-ui) are dropped: the editor
 * re-creates them on load. */

const ZPMS_NOTES_MAX_BYTES = 200000;

const ZPMS_NOTES_ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'h2', 'h3',
    'ol', 'ul', 'li', 'blockquote', 'a', 'span'];

const ZPMS_NOTES_RENAME_TAGS = ['h1' => 'h2', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3',
    'div' => 'p', 'pre' => 'p', 'section' => 'p', 'article' => 'p', 'header' => 'p', 'footer' => 'p',
    'address' => 'p', 'figure' => 'p', 'tr' => 'p', 'dt' => 'p', 'dd' => 'p'];

/* Removed together with everything inside them. */
const ZPMS_NOTES_DROP_TAGS = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
    'applet', 'template', 'svg', 'math', 'noscript', 'textarea', 'select', 'option', 'form', 'input',
    'button', 'title', 'head', 'meta', 'link', 'base', 'video', 'audio', 'source', 'canvas', 'img'];

function zpms_sanitize_notes_html(string $html): string {
    if (trim($html) === '') return '';

    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    // The meta charset makes libxml read the input as UTF-8 (Greek text);
    // the wrapper div gives one root whose children are the result.
    $doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="zpms-notes-root">'
        . $html . '</div></body></html>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $doc->getElementById('zpms-notes-root');
    if (!$root) return '';
    zpms_sanitize_notes_children($root);

    $out = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $out .= $doc->saveHTML($child);
    }
    // A Quill editor that was emptied leaves <p><br></p> behind.
    return preg_match('#^(\s|<p>(<br>)?</p>)*$#', $out) ? '' : $out;
}

function zpms_sanitize_notes_children(DOMNode $parent): void {
    foreach (iterator_to_array($parent->childNodes) as $node) {
        if ($node instanceof DOMText) continue;
        if (!($node instanceof DOMElement)) {          // comments, CDATA, PIs
            $parent->removeChild($node);
            continue;
        }
        $tag = strtolower($node->tagName);

        if (in_array($tag, ZPMS_NOTES_DROP_TAGS, true)
            || ($tag === 'span' && preg_match('/(^|\s)ql-ui(\s|$)/', $node->getAttribute('class')))) {
            $parent->removeChild($node);
            continue;
        }

        zpms_sanitize_notes_children($node);

        // Block tags outside the allowlist become the nearest allowed one,
        // so pasted <div>a</div><div>b</div> doesn't run together as "ab".
        $rename = ZPMS_NOTES_RENAME_TAGS[$tag] ?? null;
        if ($rename !== null) {
            $new = $node->ownerDocument->createElement($rename);
            while ($node->firstChild) $new->appendChild($node->firstChild);
            $parent->replaceChild($new, $node);
            $node = $new;
            $tag = $rename;
        }

        if (!in_array($tag, ZPMS_NOTES_ALLOWED_TAGS, true)) {
            // Unknown but harmless (div, font, table, ...): keep the text.
            while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
            $parent->removeChild($node);
            continue;
        }

        zpms_sanitize_notes_attributes($node, $tag);
    }
}

function zpms_sanitize_notes_attributes(DOMElement $el, string $tag): void {
    $keep = [];
    foreach (iterator_to_array($el->attributes) as $attr) {
        $name = strtolower($attr->name);
        $value = trim($attr->value);

        if ($name === 'class') {
            $tokens = array_filter(preg_split('/\s+/', $value), function ($t) {
                return (bool)preg_match('/^ql-(align-(center|right|justify)|indent-[1-8])$/', $t);
            });
            if ($tokens) $keep['class'] = implode(' ', $tokens);
        } elseif ($name === 'style') {
            $decls = [];
            foreach (explode(';', $value) as $decl) {
                if (!str_contains($decl, ':')) continue;
                [$prop, $val] = array_map('trim', explode(':', $decl, 2));
                $prop = strtolower($prop);
                if (($prop === 'color' || $prop === 'background-color')
                    && preg_match('/^(#[0-9a-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\))$/i', $val)) {
                    $decls[] = $prop . ': ' . $val;
                }
            }
            if ($decls) $keep['style'] = implode('; ', $decls) . ';';
        } elseif ($name === 'data-list' && $tag === 'li') {
            if ($value === 'bullet' || $value === 'ordered') $keep['data-list'] = $value;
        } elseif ($name === 'href' && $tag === 'a') {
            if (preg_match('#^(https?://|mailto:|tel:)#i', $value)) $keep['href'] = $value;
        }
    }

    foreach (iterator_to_array($el->attributes) as $attr) $el->removeAttribute($attr->name);
    foreach ($keep as $n => $v) $el->setAttribute($n, $v);

    if ($tag === 'a') {
        if (isset($keep['href'])) {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }
}
