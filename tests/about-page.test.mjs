import test from 'node:test';
import assert from 'node:assert/strict';

function fixture(url='https://fixture.example.test/about.html') {
  const elements=new Map(),events=new Map();
  const get=id=>{
    if(!elements.has(id))elements.set(id,{hidden:true,textContent:'',innerHTML:'',listeners:new Map(),
      addEventListener(type,callback){this.listeners.set(type,callback);},
      focus(){doc.activeElement=this;},
      click(values={}){const event={button:0,prevented:false,preventDefault(){this.prevented=true;},...values};this.listeners.get('click')?.(event);return event;}});
    return elements.get(id);
  };
  const doc={activeElement:null,referrer:'',getElementById:get,querySelector:selector=>get(selector),querySelectorAll:()=>[get('portfolio-return')]};
  const history={length:2,backCalls:0,back(){this.backCalls++;}};
  const globals={document:doc,window:{addEventListener(type,callback){events.set(type,callback);}},location:new URL(url),history};
  const originals=new Map();
  return {get,events,doc,history,install(extra={}){
    for(const [name,value]of Object.entries({...globals,...extra})){
      originals.set(name,Object.getOwnPropertyDescriptor(globalThis,name));
      Object.defineProperty(globalThis,name,{configurable:true,writable:true,value});
    }
  },restore(){for(const [name,descriptor]of originals){if(descriptor)Object.defineProperty(globalThis,name,descriptor);else delete globalThis[name];}}};
}

test('About drops late session results after leaving and refreshes its mode on return without account UI',async()=>{
  const f=fixture();let started,complete;
  const startedRequest=new Promise(resolve=>{started=resolve;});let requests=0;
  const response=()=>({ok:true,status:200,json:async()=>({authenticated:true,user:{displayName:'Fictional Account',email:'fictional@example.test'},csrfToken:'fictional-csrf-'+requests,dataMode:'live'})});
  f.install({fetch:async()=>{requests++;if(requests===1){started();return new Promise(resolve=>{complete=resolve;});}return response();}});
  try{
    const loading=import('../public/about-page.mjs?simple-header-lifecycle-test');await startedRequest;
    f.events.get('pagehide')();complete(response());await loading;
    assert.match(f.get('about-content').innerHTML,/Sign in to see your private portfolio/);
    f.events.get('pageshow')({persisted:true});for(let i=0;i<30;i++)await Promise.resolve();
    assert.equal(requests,2);assert.match(f.get('about-content').innerHTML,/same previous value of the whole portfolio/);
    assert.equal(f.get('account-name').textContent,'');assert.equal(f.get('account-email').textContent,'');
    f.events.get('pagehide')();assert.match(f.get('about-content').innerHTML,/Sign in to see your private portfolio/);
  }finally{f.restore();}
});

test('About returns through Portfolio history only from its matching app and skip navigation adds no history entry',async()=>{
  const f=fixture('https://fixture.example.test/playground/about.html?demo=1');f.install();
  try{
    await import('../public/about-page.mjs?simple-header-return-test');
    const link=f.get('portfolio-return');assert.equal(link.href,'https://fixture.example.test/playground/?demo=1');
    for(const referrer of ['', 'https://another.example.test/playground/?demo=1', 'https://fixture.example.test/playground/about.html?demo=1', 'https://fixture.example.test/playground/']){
      f.doc.referrer=referrer;assert.equal(link.click().prevented,false);
    }
    assert.equal(f.history.backCalls,0);
    f.doc.referrer='https://fixture.example.test/playground/?demo=1';
    assert.equal(link.click({ctrlKey:true}).prevented,false,'new-tab intent is not intercepted');
    assert.equal(link.click().prevented,true);assert.equal(f.history.backCalls,1);
    assert.equal(f.get('.skip-link').click().prevented,true);assert.equal(f.doc.activeElement,f.get('main'));assert.equal(f.history.backCalls,1);
    assert.equal(link.click().prevented,true);assert.equal(f.history.backCalls,2,'skip link does not put About between the user and Portfolio');
    f.history.length=1;assert.equal(link.click().prevented,false,'direct single-entry About visit has normal home navigation');
  }finally{f.restore();}
});
