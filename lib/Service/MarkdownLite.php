<?php

declare(strict_types=1);

namespace OCA\UserPods\Service;

/**
 * Minimal, safe Markdown -> HTML for the image guides (<image>.guide.md next to
 * the manifests) - the dialog's renderer (js/main.js renderMarkdown) with what
 * a longer text needs: paragraphs over several lines, numbered lists, quotes,
 * heading anchors. Everything is escaped first and markup applied after, so no
 * HTML from the file gets through. Links: http(s), site-relative /..., #...,
 * and other guides by their file name (name.guide.md), mapped by $guideUrl.
 */
class MarkdownLite {
	/** @param callable(string $image): string $guideUrl */
	public static function toHtml(string $md, callable $guideUrl): string {
		// &colon; is how the .md files keep GitHub from linking a URL; show it as ':'
		$lines = explode("\n", str_replace(["\r\n", '&colon;'], ["\n", ':'], $md));
		$html = '';
		$para = [];
		$list = '';      // '', 'ul' or 'ol'
		$quote = [];
		$code = null;    // lines of an open ``` block
		$flush = function () use (&$html, &$para, &$list, &$quote, $guideUrl): void {
			if ($para) {
				$html .= '<p>' . self::inline(implode(' ', $para), $guideUrl) . '</p>';
				$para = [];
			}
			if ($list !== '') {
				$html .= "</$list>";
				$list = '';
			}
			if ($quote) {
				$html .= '<blockquote><p>' . self::inline(implode(' ', $quote), $guideUrl) . '</p></blockquote>';
				$quote = [];
			}
		};
		foreach ($lines as $line) {
			if (preg_match('/^\s*```/', $line)) {
				if ($code === null) {
					$flush();
					$code = [];
				} else {
					$html .= '<pre><code>' . self::esc(implode("\n", $code)) . '</code></pre>';
					$code = null;
				}
				continue;
			}
			if ($code !== null) {
				$code[] = $line;
				continue;
			}
			if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
				$flush();
				$level = strlen($m[1]);
				$id = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($m[2])), '-');
				$html .= "<h$level id=\"" . self::esc($id) . "\">" . self::inline($m[2], $guideUrl) . "</h$level>";
			} elseif (preg_match('/^\s*([-*]|\d+\.)\s+(.*)$/', $line, $m)) {
				$type = ctype_digit(rtrim($m[1], '.')) ? 'ol' : 'ul';
				if ($para || $quote || ($list !== '' && $list !== $type)) {
					$flush();
				}
				if ($list === '') {
					$html .= "<$type>";
					$list = $type;
				}
				$html .= '<li>' . self::inline($m[2], $guideUrl) . '</li>';
			} elseif (preg_match('/^>\s?(.*)$/', $line, $m)) {
				if ($para || $list !== '') {
					$flush();
				}
				$quote[] = $m[1];
			} elseif (trim($line) === '') {
				$flush();
			} elseif ($list !== '' && preg_match('/^\s{2,}\S/', $line)) {
				// continuation of the last list item
				$more = ' ' . self::inline(trim($line), $guideUrl) . '</li>';
				$html = preg_replace_callback('#</li>$#', static fn () => $more, $html);
			} else {
				if ($list !== '' || $quote) {
					$flush();
				}
				$para[] = trim($line);
			}
		}
		if ($code !== null) {
			$html .= '<pre><code>' . self::esc(implode("\n", $code)) . '</code></pre>';
		}
		$flush();
		return $html;
	}

	/** The first level-1 heading, as plain text ('' if none). */
	public static function title(string $md): string {
		return preg_match('/^#\s+(.+)$/m', $md, $m) ? trim(str_replace(['**', '`'], '', $m[1])) : '';
	}

	private static function esc(string $s): string {
		return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	private static function inline(string $s, callable $guideUrl): string {
		$s = self::esc($s);
		$s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
		$s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
		$s = preg_replace('/(^|[^*])\*([^*]+)\*/', '$1<em>$2</em>', $s);
		$s = preg_replace('/(^|[^\w`])_([^_]+)_(?=[^\w`]|$)/', '$1<em>$2</em>', $s);
		$s = preg_replace_callback('/\[([^\]]+)\]\(([a-z0-9][a-z0-9_.-]*)\.guide\.md(#[a-z0-9-]*)?\)/',
			static fn ($m) => '<a href="' . self::esc($guideUrl($m[2]) . ($m[3] ?? '')) . '">' . $m[1] . '</a>', $s);
		$s = preg_replace('/\[([^\]]+)\]\((#[^)\s]*)\)/', '<a href="$2">$1</a>', $s);
		$s = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)\s]+|\/[^)\s]*)\)/',
			'<a href="$2" target="_blank" rel="noreferrer noopener">$1</a>', $s);
		return $s;
	}
}
