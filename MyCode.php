        let dataJson = await dataPromise.json();        
// console.log("monthly data\n", dataJson);
        thismonthdata = dataJson["thismonth"];
        lastmonthdata = dataJson["lastmonth"];
        thismonth_gc = Number(thismonthdata.Gc);
        thismonth_pb = Number(thismonthdata.Pb);
        thismonth_pc = Number(thismonthdata.Pc);
        if ("Pg" in thismonthdata) {
          thismonth_pg = Number(thismonthdata.Pg);
        }
        thismonth_pgt = thismonth_pb + thismonth_pc + thismonth_pg;
        thismonth_pgt = thismonth_pgt.toFixed(2);
        thismonth_pb = thismonth_pb.toFixed(2);
        thismonth_pc = thismonth_pc.toFixed(2);
        thismonth_pg = thismonth_pg.toFixed(2);
        thismonth_gc = thismonth_gc.toFixed(2);
        lastmonth_gc = Number(lastmonthdata.Gc);
        lastmonth_pb = Number(lastmonthdata.Pb);
        lastmonth_pc = Number(lastmonthdata.Pc);
        if ("Pg" in lastmonthdata) {
          lastmonth_pg = Number(lastmonthdata.Pg);
        }
        lastmonth_pgt = lastmonth_pb + lastmonth_pc + lastmonth_pg;
        lastmonth_pgt = lastmonth_pgt.toFixed(2);
        lastmonth_pb = lastmonth_pb.toFixed(2);
        lastmonth_pc = lastmonth_pc.toFixed(2);
        lastmonth_pg = lastmonth_pg.toFixed(2);
        lastmonth_gc = lastmonth_gc.toFixed(2);
        thismonth_solarproduction.innerHTML = `${thismonth_pgt} kWh`;
        thismonth_export_to_grid.innerHTML = `${thismonth_pg} kWh`;
        thismonth_import_from_grid.innerHTML = `${thismonth_gc} kWh`;
        lastmonth_solarproduction.innerHTML = `${lastmonth_pgt} kWh`;
        lastmonth_export_to_grid.innerHTML = `${lastmonth_pg} kWh`;
        lastmonth_import_from_grid.innerHTML = `${lastmonth_gc} kWh`;