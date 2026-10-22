// Every instrument, price and position in this module is fictional.
export const SNAPSHOT = '2026-10-09T13:35:00Z';
export const instruments = [
  {id:'world', name:'World Market ETF', symbol:'WMKT', exchange:'Xetra', currency:'EUR', type:'ETF', monogram:'W', tone:'sage'},
  {id:'northstar', name:'Northstar Technology', symbol:'NST', exchange:'NASDAQ', currency:'USD', type:'Stock', monogram:'N', tone:'blue'},
  {id:'europe', name:'European Leaders ETF', symbol:'EULD', exchange:'Euronext Paris', currency:'EUR', type:'ETF', monogram:'E', tone:'sand'},
  {id:'orion', name:'Orion Energy', symbol:'ORN', exchange:'NYSE', currency:'USD', type:'Stock', monogram:'O', tone:'rose'},
  {id:'horizon', name:'Horizon Bond Fund', symbol:'HZBD', exchange:'Fund NAV', currency:'EUR', type:'Fund', monogram:'H', tone:'lilac'},
  {id:'oak', name:'Oak & Co.', symbol:'OAK', exchange:'Euronext Amsterdam', currency:'EUR', type:'Stock', monogram:'O', tone:'sand'},
  {id:'pacific', name:'Pacific Markets ETF', symbol:'PCMK', exchange:'Xetra', currency:'EUR', type:'ETF', monogram:'P', tone:'blue'},
  {id:'aurora', name:'Aurora Health', symbol:'ARHA', exchange:'NASDAQ', currency:'USD', type:'Stock', monogram:'A', tone:'rose'},
  {id:'stone', name:'Stonebridge Index Fund', symbol:'STBX', exchange:'Fund NAV', currency:'EUR', type:'Fund', monogram:'S', tone:'sage'},
];
const live = {date:'2026-10-09',previousDate:'2026-10-08',observedAt:SNAPSHOT,previousObservedAt:'2026-10-08T15:35:00Z',fx:'1',previousFx:'1',status:'delayed',source:'Fictional example'};
const usd = {...live,observedAt:'2026-10-09T13:14:00Z',previousObservedAt:'2026-10-08T20:00:00Z',fx:'0.9142',previousFx:'0.9128',fxObservedAt:'2026-10-09T12:00:00Z',previousFxObservedAt:'2026-10-08T19:00:00Z'};
const nav = {...live,date:'2026-10-08',previousDate:'2026-10-07',observedAt:'2026-10-09T06:00:00Z',previousObservedAt:'2026-10-08T06:00:00Z',status:'nav'};
export const quotes = {
 world:{...live,price:'128.42',previousClose:'127.11'},
 northstar:{...usd,observedAt:'2026-10-09T13:33:00Z',price:'186.35',previousClose:'183.92'},
 europe:{...live,price:'74.68',previousClose:'74.89'},
 orion:{...usd,observedAt:'2026-10-09T13:33:00Z',price:'92.14',previousClose:'93.55'},
 horizon:{...nav,price:'23.4812',previousClose:'23.4531'},
 oak:{...live,price:'42.56',previousClose:'41.82'},
 pacific:{...live,price:'61.32',previousClose:'61.11'},
 aurora:{...usd,observedAt:'2026-10-09T13:33:00Z',price:'112.74',previousClose:'113.21'},
 stone:{...nav,price:'54.6721',previousClose:'54.3810'},
};
export function initialHoldings(){return [
 {id:'demo-world',instrumentId:'world',quantity:'125',averageCost:'111.40',eurCost:null},
 {id:'demo-northstar',instrumentId:'northstar',quantity:'24',averageCost:'154.20',eurCost:'3390'},
 {id:'demo-europe',instrumentId:'europe',quantity:'80',averageCost:'69.10',eurCost:null},
 {id:'demo-orion',instrumentId:'orion',quantity:'32',averageCost:'98.30',eurCost:'2870'},
 {id:'demo-horizon',instrumentId:'horizon',quantity:'175.5',averageCost:'22.80',eurCost:null},
];}
