import http from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { resolve, extname, sep } from 'node:path';
const root=fileURLToPath(new URL('../public/',import.meta.url));
const port=Number(process.env.PORT || 4017);
const types={'.html':'text/html; charset=utf-8','.css':'text/css; charset=utf-8','.mjs':'text/javascript; charset=utf-8','.js':'text/javascript; charset=utf-8','.svg':'image/svg+xml','.png':'image/png'};
http.createServer(async(req,res)=>{
  res.setHeader('X-Content-Type-Options','nosniff');
  res.setHeader('Cache-Control','no-store');
  res.setHeader('Referrer-Policy','no-referrer');
  res.setHeader('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
  if(!['GET','HEAD'].includes(req.method)){res.writeHead(405);res.end();return;}
  try{
    const path=decodeURIComponent(new URL(req.url,'http://localhost').pathname);
    if(path.split('/').some(p=>p.startsWith('.')) || path.startsWith('/api/') || extname(path)==='.php'){res.writeHead(404);res.end('Not found');return;}
    const file=resolve(root,`.${path==='/'?'/index.html':path}`);
    if(!file.startsWith(root.endsWith(sep)?root:root+sep) || !(await stat(file)).isFile()){res.writeHead(404);res.end('Not found');return;}
    const body=await readFile(file);res.writeHead(200,{'Content-Type':types[extname(file)]||'application/octet-stream'});res.end(req.method==='HEAD'?undefined:body);
  }catch{res.writeHead(404);res.end('Not found');}
}).listen(port,'127.0.0.1',()=>console.log(`RichYet fictional preview: http://127.0.0.1:${port}/?demo=1`));
