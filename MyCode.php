<!-- this is the my code file  -->

dataJson = await dataPromise.json();
        todayData = dataJson.ttotals;
        yesterdayData = dataJson.ytotals;
        // console.log("daily solar data\n", dataJson);
        today_pb = Number(todayData.Pb);
        today_pc = Number(todayData.Pc);
        today_gc = Number(todayData.Gc);
        if ("Pg" in todayData) {
          today_pg = Number(todayData.Pg);
        }
        today_pgt = today_pb + today_pc + today_pg;
        today_pgt = today_pgt.toFixed(2);
        today_pb = today_pb.toFixed(2);
        today_pc = today_pc.toFixed(2);
        today_pg = today_pg.toFixed(2);
        today_gc = today_gc.toFixed(2);
 
        // same logic for yesterday data
        yesterday_pb = Number(yesterdayData.Pb);
        yesterday_pc = Number(yesterdayData.Pc);
        yesterday_gc = Number(yesterdayData.Gc);
        if ("Pg" in yesterdayData) {
          yesterday_pg = Number(yesterdayData.Pg);
        }
        yesterday_pgt = yesterday_pb + yesterday_pc + yesterday_pg;
        yesterday_pgt = yesterday_pgt.toFixed(2);
        yesterday_pb = yesterday_pb.toFixed(2);
        yesterday_pc = yesterday_pc.toFixed(2);
        yesterday_pg = yesterday_pg.toFixed(2);
        yesterday_gc = yesterday_gc.toFixed(2);
 
        // set variable values on the view
        v_today_pgt.innerHTML = ${today_pgt} kWh;
        v_today_pg.innerHTML = ${today_pg} kWh;
        v_today_gc.innerHTML = ${today_gc} kWh;
 
        v_yesterday_pgt.innerHTML = ${yesterday_pgt} kWh;
        v_yesterday_pg.innerHTML = ${yesterday_pg} kWh;
        v_yesterday_gc.innerHTML = ${yesterday_gc} kWh;
 
        break;