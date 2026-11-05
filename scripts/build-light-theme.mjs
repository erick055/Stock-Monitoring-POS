import fs from 'node:fs';
import postcss from 'postcss';

const directory = new URL('../resources/css/', import.meta.url);
const colors = /#[\da-f]{3,8}\b|rgba?\([^()]+\)|\b(?:white|black)\b/gi;

function parseColor(value) {
    if (value === 'white') value = '#ffffff';
    if (value === 'black') value = '#000000';
    if (value.startsWith('#')) {
        let hex = value.slice(1);
        if (hex.length <= 4) hex = [...hex].map(c => c + c).join('');
        return [0, 2, 4].map(i => parseInt(hex.slice(i, i + 2), 16) / 255).concat(hex.length === 8 ? parseInt(hex.slice(6), 16) / 255 : 1);
    }
    const numbers = value.match(/[\d.]+/g)?.map(Number);
    if (!numbers || numbers.length < 3) return null;
    return [...numbers.slice(0, 3).map(n => n / 255), numbers[3] ?? 1];
}

function hsl([r, g, b]) {
    const max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min, l = (max + min) / 2;
    let h = 0;
    const s = d === 0 ? 0 : d / (1 - Math.abs(2 * l - 1));
    if (d) h = max === r ? ((g - b) / d + (g < b ? 6 : 0)) : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
    return [h * 60, s, l];
}

function transform(value, kind, retainText = false) {
    return value.replace(colors, (match) => {
        const rgba = parseColor(match.toLowerCase());
        if (!rgba) return match;
        let [h, s, l] = hsl(rgba);
        const alpha = rgba[3];
        if (kind === 'shadow') return `rgb(31 39 65 / ${Math.min(alpha, .09)})`;
        if (kind === 'surface') {
            if (s > .45 && l >= .38) return match;
            if (l > .8) return match;
            l = .98 - l * .13;
            s = Math.min(s, .32);
        } else if (kind === 'border') {
            l = .84;
            s = Math.min(s, .38);
        } else {
            if (retainText) return match;
            if (s < .18) return l > .75 ? '#202338' : l > .35 ? '#626b80' : '#202338';
            s = Math.max(.4, Math.min(s, .8));
            l = .35;
        }
        return `hsl(${Math.round(h)} ${Math.round(s * 100)}% ${Math.round(l * 100)}% / ${alpha})`;
    });
}

const output = postcss.root();
output.append(postcss.comment({ text: 'Light appearance palette. Regenerate with node scripts/build-light-theme.mjs after changing screen styles.' }));
for (const file of fs.readdirSync(directory).filter(name => name.endsWith('.css') && !['appearance.css', 'light-palette.css', 'receipt.css', 'app.css'].includes(name))) {
    const sheet = postcss.parse(fs.readFileSync(new URL(file, directory), 'utf8'));
    sheet.walkRules(rule => {
        if (rule.parent.type === 'atrule' && /keyframes/.test(rule.parent.name)) return;
        if (!rule.nodes) return;
        const background = rule.nodes.find(d => /^background(?:-color)?$/.test(d.prop));
        const solidAction = background && !/gradient/.test(background.value) && [...background.value.matchAll(colors)].some(m => {
            const color = parseColor(m[0].toLowerCase());
            if (!color) return false;
            const [, s, l] = hsl(color);
            return s > .45 && l >= .38 && color[3] > .8;
        });
        const clone = postcss.rule({ selector: rule.selectors.map(selector => {
            if (selector.includes(':root')) return selector.replaceAll(':root', ':root[data-theme="light"]');
            if (/^html(?=[\s.{:#>]|$)/.test(selector)) return selector.replace(/^html/, 'html[data-theme="light"]');
            return `html[data-theme="light"] ${selector}`;
        }).join(',\n') });
        rule.nodes.forEach(decl => {
            if (decl.type !== 'decl' || !decl.value.match(colors)) return;
            const prop = decl.prop;
            const kind = /shadow/.test(prop) ? 'shadow' : /border|outline/.test(prop) ? 'border' : /background|^--(?:bg|surface|panel|border|line)/.test(prop) ? 'surface' : /color|fill|stroke|^--/.test(prop) ? 'text' : null;
            if (!kind) return;
            clone.append(decl.clone({ value: transform(decl.value, kind, kind === 'text' && solidAction) }));
        });
        if (!clone.nodes?.length) return;
        let node = clone;
        for (let parent = rule.parent; parent?.type === 'atrule'; parent = parent.parent) {
            const wrapper = postcss.atRule({ name: parent.name, params: parent.params });
            wrapper.append(node); node = wrapper;
        }
        output.append(node);
    });
}
fs.writeFileSync(new URL('light-palette.css', directory), output.toString() + '\n');
