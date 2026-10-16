(function (root) {
  'use strict';
  // Preserve SQL token positions while hiding literals, quoted identifiers and comments.
  function codeOnly(sql) {
    return sql.replace(/'(?:[^']|'')*(?:'|$)|\[(?:[^\]]|\]\])*(?:\]|$)|--[^\n]*|\/\*[\s\S]*?(?:\*\/|$)/g, value => ' '.repeat(value.length));
  }
  function risk(sql) {
    const code = codeOnly(sql);
    const action = /^\s*(UPDATE|DELETE)\b/i.exec(code)?.[1].toUpperCase() || '';
    return { action, missingWhere: !!action && !/\bWHERE\b/i.test(code) };
  }
  function completion(sql, cursor, columns) {
    const before = sql.slice(0, cursor);
    // Do not complete inside values or comments. An open bracket is an identifier.
    let state = 'code';
    for (let i = 0; i < before.length; i++) {
      const c = before[i], next = before[i + 1];
      if (state === 'string') { if (c === "'" && next === "'") i++; else if (c === "'") state = 'code'; }
      else if (state === 'line') { if (c === '\n') state = 'code'; }
      else if (state === 'comment') { if (c === '*' && next === '/') { state = 'code'; i++; } }
      else if (state === 'bracket') { if (c === ']' && next === ']') i++; else if (c === ']') state = 'code'; }
      else if (c === "'") state = 'string';
      else if (c === '-' && next === '-') { state = 'line'; i++; }
      else if (c === '/' && next === '*') { state = 'comment'; i++; }
      else if (c === '[') state = 'bracket';
    }
    if (!['code','bracket'].includes(state)) return null;
    const match = /\[?([\p{L}\p{N}_]+)\*?$/u.exec(before);
    if (!match) return null;
    const prefix = match[1].toLowerCase();
    const matches = columns.filter(column => column.name.toLowerCase().startsWith(prefix));
    if (!matches.length) return null;
    const tail = /^[\p{L}\p{N}_]*(?:\]|\*)?/u.exec(sql.slice(cursor))[0];
    return { start: cursor - match[0].length, end: cursor + tail.length, prefix, matches: matches.slice(0, 30) };
  }
  function target(value) {
    if (!value.includes('.')) return null;
    const identifier = '(?:\\[(?:[^\\]]|\\]\\])+\\]|[^.\\[\\]\\s]+)';
    const match = new RegExp('^\\s*(' + identifier + ')\\s*\\.\\s*(' + identifier + ')?\\s*\\.\\s*(' + identifier + ')\\s*$').exec(value);
    if (!match) throw new Error('ระบุชื่อแบบ Database..Table หรือ Database.Schema.Table เช่น NMU_ERP..sp_tor');
    const unquote = name => name?.startsWith('[') ? name.slice(1, -1).replace(/\]\]/g, ']') : name;
    return { database: unquote(match[1]), schema: unquote(match[2]) || 'dbo', table: unquote(match[3]) };
  }
  const api = { risk, completion, target };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.SQL_EDITOR = api;
})(typeof window === 'undefined' ? globalThis : window);
