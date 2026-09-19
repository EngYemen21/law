#!/usr/bin/env node
/**
 * **جرد الواجهة — ضمانةٌ ألّا يضيع زرٌّ أو لونٌ أو تبويبٌ عند تعديل الشاشات.**
 *
 * لماذا: الواجهة بلا اختبارات تصيير، وتعديلٌ في الخادم (حذف حالةٍ قديمة مثلاً) قد يُسقط
 * زرّاً أو يغيّر لون شارةٍ بصمت. هذه الأداة تلتقط من كلّ ملفّ `.tsx/.ts` في `resources/js`
 * ما يراه المستخدم أو يحدّد شكله — **بعدد مرّاته** — فتكشف المقارنةُ قبل/بعد أيَّ عنصرٍ نقص،
 * حتى لو بقي له نظيرٌ في ملفٍّ آخر أو في الملفّ نفسه.
 *
 * ما يُجرَد لكلّ ملفّ:
 * - `text`   النصوص العربيّة الظاهرة: عُقد JSX النصّيّة والسلاسل الحرفيّة (تسميات الأزرار والتبويبات والشارات والرسائل).
 * - `tone`   أصناف الألوان `b-*` و`t-*` (شارات وبطاقات إحصاء وإشعارات).
 * - `class`  أصناف الأزرار والمكوّنات `btn*` ومتغيّراتها كما كُتبت في `className`.
 * - `icon`   أسماء الأيقونات `<Icon name="…">`.
 * - `color`  الألوان الست عشريّة ومتغيّرات CSS `var(--…)`.
 * - `route`  المسارات المكتوبة نصّاً (`'/admin/…'`، `` `/lawyer/${…}` ``).
 * - `css`    قواعد CSS المضمّنة في الصفحات (`<style>{`…`}</style>`) قاعدةً قاعدة، خصائصها مرتّبة.
 *
 * الاستعمال:
 *   node scripts/ui-inventory.mjs snapshot <out.json>
 *   node scripts/ui-inventory.mjs diff <before.json> <after.json> [allow.json]
 *
 * `allow.json` (اختياريّ): ما أُعلن حذفه عمداً بقرار، لكلّ ملفٍّ وفئة إمّا قائمة قيم (أيّ نقص)
 * أو كائن `{ "القيمة": أقصى عددٍ يُحذف }` للعناصر المشتركة:
 *   `{ "file.tsx": { "text": ["نتيجة الجلسة"], "tone": { "b-amber": 1 } } }`
 * أيّ نقصٍ خارجه يُطبع «ناقص» ويخرج الأمر برمز 1 فيوقف العمل.
 * المجلّدات المولَّدة (`resources/js/actions`، `resources/js/routes`) مستثناة.
 */
import { readFileSync, readdirSync, statSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';

const ROOT = process.cwd();
const SRC = join(ROOT, 'resources', 'js');
const SKIP = [join(SRC, 'actions'), join(SRC, 'routes'), join(SRC, 'wayfinder')];

/** @returns {string[]} */
function files(dir) {
    const out = [];
    for (const name of readdirSync(dir)) {
        const full = join(dir, name);
        if (SKIP.some((s) => full === s || full.startsWith(s + sep))) continue;
        if (statSync(full).isDirectory()) out.push(...files(full));
        else if (/\.(tsx|ts)$/.test(name) && !/\.d\.ts$/.test(name)) out.push(full);
    }
    return out;
}

const ARABIC = /[؀-ۿ]/;

/** يزيد عدّاد القيمة في الفئة */
function bump(bag, kind, value) {
    const v = value.replace(/\s+/g, ' ').trim();
    if (!v) return;
    bag[kind] ??= {};
    bag[kind][v] = (bag[kind][v] ?? 0) + 1;
}

/**
 * **قارئٌ حرفاً بحرف** يفصل السلاسل النصّيّة والتعليقات عن الكود.
 *
 * لماذا لا تعبيرٌ نمطيّ: قالبٌ مثل `` `x ${a}` `` يفتح ويغلق بعلامةٍ واحدة، فكان التعبير يزاوج
 * خطأً بين إغلاق قالبٍ وفتح التالي فيبتلع صفحةً كاملة «نصّاً واحداً» — فيخفى حذفُ زرٍّ داخلها.
 *
 * @returns {{ strings: string[], masked: string }} محتوى كلّ سلسلة (القالب: أجزاؤه الثابتة و`*` مكان `${}`)،
 * والكود نفسه وقد مُحيت منه السلاسل والتعليقات (لقراءة عُقد JSX النصّيّة دون خلطٍ بها).
 */
function lex(src) {
    const strings = [];
    let masked = '';
    let i = 0;
    const n = src.length;
    while (i < n) {
        const ch = src[i];
        const next = src[i + 1];
        if (ch === '/' && next === '/') { // تعليق سطر
            while (i < n && src[i] !== '\n') i++;
            continue;
        }
        if (ch === '/' && next === '*') { // تعليق كتلة (ومنه {/* … */} في JSX)
            const end = src.indexOf('*/', i + 2);
            i = end === -1 ? n : end + 2;
            continue;
        }
        if (ch === "'" || ch === '"') {
            let j = i + 1;
            let s = '';
            while (j < n && src[j] !== ch && src[j] !== '\n') {
                if (src[j] === '\\') { s += src[j + 1] ?? ''; j += 2; continue; }
                s += src[j++];
            }
            strings.push(s);
            masked += ' ';
            i = j + 1;
            continue;
        }
        if (ch === '`') {
            let j = i + 1;
            let s = '';
            while (j < n && src[j] !== '`') {
                if (src[j] === '\\') { s += src[j + 1] ?? ''; j += 2; continue; }
                if (src[j] === '$' && src[j + 1] === '{') { // ${…} بأقواسٍ متداخلة
                    let depth = 1;
                    j += 2;
                    while (j < n && depth > 0) {
                        if (src[j] === '{') depth++;
                        else if (src[j] === '}') depth--;
                        j++;
                    }
                    s += '*';
                    continue;
                }
                s += src[j++];
            }
            strings.push(s);
            masked += ' ';
            i = j + 1;
            continue;
        }
        masked += ch;
        i++;
    }
    return { strings, masked };
}

/** جرد ملفٍّ واحد: الغرض ضمّ كلّ ما يُرى أو يحدّد شكله، لا تحليلُ JSX كاملاً. */
function scan(src) {
    const bag = {};
    const { strings, masked } = lex(src);

    // السلاسل: نصوصٌ ظاهرة (عربيّة) ومساراتٌ (تبدأ بـ/)، وكتل CSS المضمّنة قاعدةً قاعدة
    for (const s of strings) {
        if (/[.#@\w-][^{}]*\{[^{}]*:[^{}]*\}/.test(s)) {
            // <style>{`…`}</style>: كلّ قاعدة «محدِّد { خصائص }» عنصرٌ مستقلّ، فتغيُّر لونٍ واحد يُرى وحده
            const css = s.replace(/\/\*[\s\S]*?\*\//g, ' ');
            for (const m of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
                const decls = m[2].split(';').map((d) => d.trim()).filter(Boolean).sort().join('; ');
                bump(bag, 'css', `${m[1].trim()} { ${decls} }`);
            }
            continue;
        }
        if (ARABIC.test(s)) bump(bag, 'text', s);
        if (/^\/[a-z][\w\-/{}.:?=&*]*$/i.test(s.trim())) bump(bag, 'route', s.trim());
    }
    // عُقد JSX النصّيّة: >نصّ< خارج الأقواس — من الكود بعد محو السلاسل والتعليقات؛
    // و(?<![=>]) كي لا يُعدّ سهمُ دالّة `=>` بدايةَ عقدة
    for (const m of masked.matchAll(/(?<![=>-])>([^<>{}]*[؀-ۿ][^<>{}]*)</g)) {
        bump(bag, 'text', m[1]);
    }
    const code = src; // الأصناف والأيقونات والألوان تُقرأ من النصّ كما كُتب
    // أصناف الألوان والمكوّنات أينما وردت (className، tone=، قيم props)
    for (const m of code.matchAll(/\b([bt]-(?:grey|gray|blue|amber|green|red|cyan|purple|teal|muted|[a-z]+))\b/g)) {
        bump(bag, 'tone', m[1]);
    }
    for (const m of code.matchAll(/className\s*=\s*(?:"([^"]*)"|'([^']*)'|\{`([^`]*)`\})/g)) {
        const cls = (m[1] ?? m[2] ?? m[3] ?? '').replace(/\$\{[^}]*\}/g, '*');
        if (/\bbtn\b|\bbadge|\bchip\b|\bpill\b|\btab/.test(cls)) bump(bag, 'class', cls);
    }
    for (const m of code.matchAll(/<Icon\b[^>]*\bname\s*=\s*["']([\w-]+)["']/g)) bump(bag, 'icon', m[1]);
    for (const m of code.matchAll(/(#[0-9a-fA-F]{3,8})\b|var\(--([\w-]+)\)/g)) bump(bag, 'color', m[1] ?? `var(--${m[2]})`);

    return bag;
}

function snapshot(out) {
    const inv = {};
    for (const f of files(SRC).sort()) {
        const rel = relative(ROOT, f).split(sep).join('/');
        const bag = scan(readFileSync(f, 'utf8'));
        if (Object.keys(bag).length) inv[rel] = bag;
    }
    mkdirSync(dirname(out), { recursive: true });
    writeFileSync(out, JSON.stringify(inv, null, 1));
    const n = Object.values(inv).reduce((a, b) => a + Object.values(b).reduce((x, y) => x + Object.values(y).reduce((p, q) => p + q, 0), 0), 0);
    console.log(`جرد: ${Object.keys(inv).length} ملفّاً، ${n} عنصراً ← ${out}`);
}

function diff(beforePath, afterPath, allowPath) {
    const before = JSON.parse(readFileSync(beforePath, 'utf8'));
    const after = JSON.parse(readFileSync(afterPath, 'utf8'));
    const allow = allowPath ? JSON.parse(readFileSync(allowPath, 'utf8')) : {};
    let missing = 0;
    let allowed = 0;
    const added = [];

    for (const [file, kinds] of Object.entries(before)) {
        for (const [kind, values] of Object.entries(kinds)) {
            for (const [value, count] of Object.entries(values)) {
                const now = after[file]?.[kind]?.[value] ?? 0;
                if (now >= count) continue;
                // السماح إمّا قائمة (أيّ نقص) أو كائن { القيمة: أقصى نقصٍ مسموح } — الأدقّ للعناصر المشتركة
                // («btn sm» يبقى منه في الملفّ غيرُ المحذوف، فيُسمح بنقص واحدٍ لا بزواله)
                const rule = allow[file]?.[kind];
                const ok = Array.isArray(rule)
                    ? rule.includes(value)
                    : rule !== undefined && value in rule && count - now <= rule[value];
                if (ok) allowed++;
                else missing++;
                console.log(`${ok ? 'محذوفٌ بقرار' : 'ناقص'}  ${file}  [${kind}]  «${value}»  ${count} ← ${now}`);
            }
        }
    }
    for (const [file, kinds] of Object.entries(after)) {
        for (const [kind, values] of Object.entries(kinds)) {
            for (const [value, count] of Object.entries(values)) {
                const was = before[file]?.[kind]?.[value] ?? 0;
                if (count > was) added.push(`${file}  [${kind}]  «${value}»  ${was} ← ${count}`);
            }
        }
    }
    if (added.length) console.log(`\nمُضاف (${added.length}):\n  ` + added.join('\n  '));
    console.log(`\nالخلاصة: ناقص ${missing} · محذوف بقرار ${allowed} · مُضاف ${added.length}`);
    process.exit(missing > 0 ? 1 : 0);
}

const [, , cmd, a, b, c] = process.argv;
if (cmd === 'snapshot' && a) snapshot(a);
else if (cmd === 'diff' && a && b) diff(a, b, c);
else {
    console.error('الاستعمال: node scripts/ui-inventory.mjs snapshot <out.json> | diff <before.json> <after.json> [allow.json]');
    process.exit(2);
}
