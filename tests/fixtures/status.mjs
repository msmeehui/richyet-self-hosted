// Synthetic status only: no real accounts, positions, keys, or source requests.
export const owner = {email:'owner@example.test',displayName:'Fictional Owner',canViewStatus:true};
export function statusFixture() {
  return {
    schemaVersion:1,checkedAt:'2026-10-13T12:10:00Z',cacheUpdatedAt:'2026-10-13T12:00:02Z',
    collectorLastAttemptAt:'2026-10-13T12:00:00Z',collectorNextCheckAt:'2026-10-13T12:10:00Z',collectionPausedUntil:null,
    manualRefresh:{enabled:false,reason:'Status reads saved information only. Price updates are scheduled on the server.'},
    providers:[
      {id:'alpaca',label:'Alpaca',periodSeconds:3600,internalLimit:300,used:0,remaining:300,windowStart:'2026-10-13T12:00:00Z',resetAt:'2026-10-13T13:00:00Z',currentHourUsed:0,previousHourUsed:8,todayUsed:24,dayStart:'2026-10-13T00:00:00Z',providerRemaining:null,providerAllowance:{text:'Basic: 200 historical requests per minute.',url:'https://docs.alpaca.markets/docs/about-market-data-api'},note:'RichYet’s hourly safety limit is separate from the provider’s per-minute allowance.'},
      {id:'eodhd',label:'EODHD',periodSeconds:86400,internalLimit:18,used:2,remaining:16,windowStart:'2026-10-13T00:00:00Z',resetAt:'2026-10-14T00:00:00Z',currentHourUsed:null,previousHourUsed:null,todayUsed:2,dayStart:'2026-10-13T00:00:00Z',providerRemaining:null,providerAllowance:{text:'Free: 20 weighted API calls per day.',url:'https://eodhd.com/financial-apis/api-limits'},note:'Hourly history is not recorded for this ledger.'},
    ],
    holdings:[
      {listingId:'fictional-oak',name:'Fictional Oak Shares',symbol:'OAK',exchange:'Example Exchange',currency:'EUR',source:'Synthetic fixture',priceDate:'2026-10-13',observedAt:'2026-10-13T11:45:00Z',publicationAt:'2026-10-13T11:50:00Z',retrievedAt:'2026-10-13T12:00:00Z',baselineDate:'2026-10-12',baselineObservedAt:null,status:'delayed',sourceAvailable:true,priceAvailable:true,baselineAvailable:true,issues:[],checks:[{id:'quote',label:'Price check',lastAttemptAt:'2026-10-13T12:00:00Z',lastSuccessAt:'2026-10-13T12:00:00Z',nextEligibleAt:'2026-10-13T13:00:00Z',note:'A saved observation; no new provider request is made by this page.'}]},
      {listingId:'fictional-aurora',name:'Fictional Aurora Shares',symbol:'AURA',exchange:'Example US Market',currency:'USD',source:'Synthetic fixture',priceDate:'2026-10-12',observedAt:null,publicationAt:null,retrievedAt:'2026-10-13T12:00:00Z',baselineDate:'2026-10-09',baselineObservedAt:null,status:'closed',sourceAvailable:true,priceAvailable:true,baselineAvailable:true,issues:[],checks:[{id:'quote',label:'Price check',lastAttemptAt:null,lastSuccessAt:null,nextEligibleAt:null,note:'These check times are not retained by this source.'}]},
      {listingId:'fictional-horizon',name:'Fictional Horizon Fund',symbol:'FUND',exchange:'Fund provider',currency:'EUR',source:'Synthetic fixture',priceDate:'2026-10-09',observedAt:null,publicationAt:null,retrievedAt:'2026-10-13T12:00:00Z',baselineDate:'2026-10-08',baselineObservedAt:null,status:'nav',sourceAvailable:true,priceAvailable:true,baselineAvailable:true,issues:[],checks:[]},
    ],
  };
}
