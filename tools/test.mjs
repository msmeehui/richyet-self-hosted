// Offline regression tests. Never loads an installation's configuration or credentials.
import {execFileSync, spawnSync} from 'node:child_process';
import {readdirSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
const root=fileURLToPath(new URL('../',import.meta.url));
let php;
try {
  php=execFileSync(process.env.PHP_BINARY || 'php',['-r',
    'if (!extension_loaded("pdo_sqlite")) { fwrite(STDERR,"Tests need PDO SQLite." . PHP_EOL); exit(1); } echo PHP_BINARY;'],
    {encoding:'utf8',cwd:root}).trim();
} catch {
  console.error('Install PHP with PDO SQLite, or set PHP_BINARY to that executable. No tests ran.');
  process.exit(1);
}
function run(command,args) {
  const result=spawnSync(command,args,{cwd:root,env:{...process.env,PHP_BINARY:php},stdio:'inherit'});
  if(result.error || result.status!==0) process.exit(result.status || 1);
}
run(process.execPath,['--test',...readdirSync(new URL('../tests/',import.meta.url)).filter(name=>name.endsWith('.test.mjs')).sort().map(name=>`tests/${name}`)]);
for(const file of ['self-host-bootstrap','self-host-catalogue','last-valid-market','market-retention-recovery','retention-provider-scope','incomplete-identity-retention','reporting','market-calendars']) {
  run(php,['-d','allow_url_fopen=0',`tests/${file}.test.php`]);
}
