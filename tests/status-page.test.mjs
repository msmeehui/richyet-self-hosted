import test from 'node:test';
import assert from 'node:assert/strict';
import {createStatusPage} from '../public/status-page.mjs';
import {owner,statusFixture} from './fixtures/status.mjs';

const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return{promise,resolve,reject};};
const settle=async()=>{for(let i=0;i<20;i++)await Promise.resolve();};
function fixture({user=owner,sessionImpl,statusImpl}={}) {
  const elements=new Map(),windowEvents=new Map(),calls=[];
  const doc={body:{dataset:{api:'./api/index.php',appHome:'../'}},activeElement:null,
    getElementById(id){
      if(!elements.has(id))elements.set(id,{hidden:id!=='status-message',disabled:false,textContent:'',innerHTML:'',href:'',attributes:new Map(),listeners:new Map(),
        setAttribute(key,value){this.attributes.set(key,value);},
        addEventListener(type,callback){const handlers=this.listeners.get(type)||[];handlers.push(callback);this.listeners.set(type,handlers);},
        focus(){doc.activeElement=this;},contains(target){return [...elements.values()].includes(target);},
        replaceChildren(){this.innerHTML='';this.textContent='';},
        emit(type,values={}){return Promise.all((this.listeners.get(type)||[]).map(fn=>fn({target:this,preventDefault(){},...values})));},
      });return elements.get(id);
    },addEventListener(){},
  };
  const win={addEventListener(type,callback){windowEvents.set(type,callback);}};
  const client={user:null,
    async session(){calls.push('session');const value=sessionImpl?await sessionImpl():user;this.user=value;return value;},
    async loadStatus(options){calls.push('status');return statusImpl?statusImpl(options):statusFixture();},
  };
  const page=createStatusPage({doc,win,client});
  return{...page,doc,client,calls,elements,get:id=>doc.getElementById(id),emit:(type,event={})=>windowEvents.get(type)?.(event)};
}

test('owner status is a cache-only read and refresh rechecks access without any mutation',async()=>{
  const f=fixture();await f.ready;
  assert.deepEqual(f.calls,['session','status']);
  assert.equal(f.get('status-content').hidden,false);assert.match(f.get('status-content').innerHTML,/Fictional Oak/);
  assert.equal(f.elements.has('account-controls'),false);assert.equal(f.get('status-portfolio-return').href,'../');
  await f.reload();assert.deepEqual(f.calls,['session','status','session','status']);
  assert.equal(f.get('status-sign-in').href,'../');
});

test('anonymous and non-owner sessions never request privileged status',async()=>{
  for(const user of [null,{email:'other@example.test'}, {...owner,canViewStatus:'true'}, {...owner,canViewStatus:false}]){
    const f=fixture({user});await f.ready;
    assert.deepEqual(f.calls,['session']);assert.equal(f.get('status-content').innerHTML,'');
    assert.equal(f.get('status-content').hidden,true);assert.equal(f.elements.has('account-controls'),false);
    assert.equal(f.get('status-refresh').hidden,true);
  }
});

test('refresh coalesces while status is pending and clears prior data before authentication',async()=>{
  const pending=deferred();let count=0;
  const f=fixture({statusImpl:()=>++count===1?statusFixture():pending.promise});await f.ready;
  const next=f.reload();await settle();
  assert.equal(f.get('status-content').innerHTML,'');assert.equal(f.get('status-refresh').disabled,true);
  await f.reload();assert.equal(count,2);
  pending.resolve(statusFixture());await next;assert.equal(f.get('status-refresh').disabled,false);
});

test('expired and forbidden responses redact previous content without exposing server errors',async()=>{
  for(const status of [401,403]){
    let fail=false;const f=fixture({statusImpl:()=>{if(fail)throw Object.assign(new Error('PRIVATE_DEBUG'),{status});return statusFixture();}});
    await f.ready;fail=true;await f.reload();
    assert.equal(f.get('status-content').innerHTML,'');assert.equal(f.get('status-refresh').hidden,true);
    assert.equal(f.elements.has('account-controls'),false);
    assert.doesNotMatch(f.get('status-message').textContent,/PRIVATE_DEBUG/);
  }
});

test('connection failure removes old content and retries without substituting empty counters',async()=>{
  let fail=false;const f=fixture({statusImpl:()=>{if(fail)throw new Error('private network trace');return statusFixture();}});
  await f.ready;fail=true;await f.reload();
  assert.equal(f.get('status-content').innerHTML,'');assert.match(f.get('status-message').textContent,/could not be loaded/);
  assert.equal(f.get('status-refresh').hidden,false);
  fail=false;await f.reload();assert.match(f.get('status-content').innerHTML,/Fictional Oak/);
});

test('leaving during the first session cannot reveal late account data; restoring reauthenticates',async()=>{
  const pending=deferred();let count=0;
  const f=fixture({sessionImpl:()=>++count===1?pending.promise:owner});
  f.emit('pagehide');pending.resolve(owner);await f.ready;
  assert.deepEqual(f.calls,['session']);assert.equal(f.elements.has('account-controls'),false);
  assert.equal(f.get('status-content').innerHTML,'');
  f.emit('pageshow',{persisted:true});await settle();
  assert.deepEqual(f.calls,['session','session','status']);assert.equal(f.get('status-content').hidden,false);
});

test('leaving during status aborts and discards its late response, including after an account switch',async()=>{
  const pending=deferred();let signal,signedIn=true;
  const f=fixture({sessionImpl:()=>signedIn?owner:{email:'other@example.test'},statusImpl:options=>{signal=options.signal;return pending.promise;}});
  await settle();f.emit('pagehide');assert.equal(signal.aborted,true);
  signedIn=false;f.emit('pageshow',{persisted:true});await settle();pending.resolve(statusFixture());await f.ready;
  assert.equal(f.get('status-content').innerHTML,'');assert.equal(f.get('status-refresh').hidden,true);
  assert.equal(f.elements.has('account-controls'),false);
});
