// Offline calendar generation and review report. Never scrapes or calls providers.
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const source=JSON.parse(fs.readFileSync(path.join(root,'data/market-calendars.json'),'utf8'));
const validDate=day=>/^\d{4}-\d{2}-\d{2}$/.test(day)&&Number.isFinite(Date.parse(`${day}T00:00:00Z`))&&new Date(`${day}T00:00:00Z`).toISOString().slice(0,10)===day;
const fail=message=>{throw new Error(`Invalid calendar source: ${message}`);};
if(source.schemaVersion!==1||!validDate(source.reviewedOn)||!validDate(source.reviewBy)||source.reviewBy<source.reviewedOn)fail('review metadata');
for(const [name,calendar] of Object.entries(source.calendars)){
  if(![calendar.openMinute,calendar.closeMinute,calendar.approxCloseMinute].every(x=>Number.isInteger(x)&&x>=0&&x<1440)||calendar.closeMinute<=calendar.openMinute)fail(`${name} hours`);
  for(const [year,row] of Object.entries(calendar.years)){
    if(!/^20\d{2}$/.test(year))fail(`${name} year`);
    for(const field of ['closed','uncertainHours'])if(!Array.isArray(row[field])||new Set(row[field]).size!==row[field].length||row[field].some(day=>!validDate(`${year}-${day}`)))fail(`${name} ${year} ${field}`);
    for(const [day,minute] of Object.entries(row.earlyCloses))if(!validDate(`${year}-${day}`)||!Number.isInteger(minute)||minute<=calendar.openMinute||minute>=calendar.closeMinute||row.closed.includes(day)||row.uncertainHours.includes(day))fail(`${name} early close`);
    if(row.closed.some(day=>row.uncertainHours.includes(day)))fail(`${name} conflicting day`);
  }
}
for(const [mic,venue] of Object.entries(source.venues))if(!/^[A-Z]{4}$/.test(mic)||!source.calendars[venue.calendar]||!Array.isArray(venue.years)||!venue.years.length||new Set(venue.years).size!==venue.years.length||venue.years.some(year=>!source.calendars[venue.calendar].years[year]))fail(`${mic} coverage`);
for(const [name,[mic,type,zone,from]] of Object.entries(source.schedules))if(!/^[a-z0-9_]+$/.test(name)||!source.venues[mic]||!['Stock','ETF'].includes(type)||!zone.startsWith('Europe/')||!validDate(from))fail(`${name} identity`);
const compact=JSON.stringify(source);
const outputs={
  'public/lib/market-calendar-data.mjs':`// Generated from data/market-calendars.json; run node tools/update-market-calendars.mjs.\nconst freeze=value=>{if(value&&typeof value==='object'){Object.values(value).forEach(freeze);Object.freeze(value);}return value;};\nexport const MARKET_CALENDARS=freeze(${compact});\n`,
  'server/MarketCalendarData.php':`<?php\ndeclare(strict_types=1);\nnamespace RichYet;\n// Generated from data/market-calendars.json; run node tools/update-market-calendars.mjs.\nfinal class MarketCalendarData\n{\n    public const JSON = '${compact.replaceAll('\\','\\\\').replaceAll("'","\\'")}';\n}\n`,
};
const args=process.argv.slice(2);
if(args[0]==='--audit'){
  const day=args[1]??new Date().toISOString().slice(0,10);if(!validDate(day))fail('audit date');
  const nextYear=String(Number(day.slice(0,4))+1);
  const pendingVenues=Object.entries(source.venues).filter(([,v])=>!v.years.includes(nextYear)).map(([mic])=>mic);
  console.log(JSON.stringify({reviewedOn:source.reviewedOn,reviewBy:source.reviewBy,reviewDue:day>=source.reviewBy,nextYear,pendingVenues,pending:source.pending},null,2));
}else{
  if(args.length&&args[0]!=='--check')throw new Error('Use no argument, --check, or --audit YYYY-MM-DD.');
  for(const [name,content] of Object.entries(outputs)){
    const file=path.join(root,name);
    if(args[0]==='--check'){if(!fs.existsSync(file)||fs.readFileSync(file,'utf8')!==content)throw new Error(`Calendar artifact is out of date: ${name}`);}
    else fs.writeFileSync(file,content);
  }
  console.log(args[0]==='--check'?'Calendar artifacts match the reviewed source.':'Generated both calendar artifacts.');
}
