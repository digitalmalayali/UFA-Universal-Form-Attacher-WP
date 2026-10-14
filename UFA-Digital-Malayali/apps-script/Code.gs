/**
 * Forms to Google Sheets – Apps Script webhook receiver (version 2).
 *
 * 1. Open your Google Sheet > Extensions > Apps Script.
 * 2. Replace everything in Code.gs with this file and click Save.
 * 3. Deploy > New deployment > type "Web app".
 *      Execute as: Me   |   Who has access: Anyone
 * 4. Copy the Web app URL (ends in /exec) into the WordPress plugin.
 *
 * After changing this code later: Deploy > Manage deployments > Edit (pencil)
 * > Version: New version > Deploy. The URL stays the same.
 *
 * - New form fields automatically become new columns.
 * - Rows are matched to headers by name, so you can reorder columns freely.
 * - Rows whose "Entry ID" is already in the sheet are skipped (no duplicates).
 */

// Must match the "Secret" of the connection in WordPress.
// Several connections can share this sheet: ['secret-one', 'secret-two'].
var SECRETS = ['{{SECRET}}'];

var VERSION = 2;

function doPost(e) {
  var lock = LockService.getScriptLock();
  try {
    lock.waitLock(30000);
  } catch (err) {
    return json_({ status: 'error', message: 'Sheet is busy, will retry', version: VERSION });
  }
  try {
    if (!e || !e.postData || !e.postData.contents) {
      return json_({ status: 'error', message: 'Empty request', version: VERSION });
    }
    var p = JSON.parse(e.postData.contents);

    if (SECRETS.indexOf(String(p.secret)) === -1) {
      return json_({ status: 'error', message: 'Invalid secret', version: VERSION });
    }

    var rows = Array.isArray(p.rows) ? p.rows : (p.data ? [p.data] : []);
    if (!rows.length) {
      return json_({ status: 'success', message: 'Nothing to add', added: 0, skipped: 0, version: VERSION });
    }

    var idColumn = String(p.id_column || 'Entry ID');
    var columns = Array.isArray(p.columns) && p.columns.length ? p.columns.map(String) : Object.keys(rows[0]);
    var name = String(p.sheet || 'Sheet1').slice(0, 99);
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var sheet = ss.getSheetByName(name) || ss.insertSheet(name);

    // 1. Headers: read the first row and add any new columns at the end.
    var lastCol = sheet.getLastColumn();
    var headers = lastCol ? sheet.getRange(1, 1, 1, lastCol).getValues()[0].map(String) : [];
    while (headers.length && headers[headers.length - 1] === '') {
      headers.pop();
    }
    var missing = columns.filter(function (c) { return c !== '' && headers.indexOf(c) === -1; });
    if (missing.length) {
      sheet.getRange(1, headers.length + 1, 1, missing.length).setValues([missing]).setFontWeight('bold');
      headers = headers.concat(missing);
      if (sheet.getFrozenRows() === 0) {
        sheet.setFrozenRows(1);
      }
    }

    // 2. Existing Entry IDs, so the same submission is never added twice.
    var seen = {};
    var idIndex = headers.indexOf(idColumn);
    var lastRow = sheet.getLastRow();
    if (idIndex !== -1 && lastRow > 1) {
      var ids = sheet.getRange(2, idIndex + 1, lastRow - 1, 1).getValues();
      for (var i = 0; i < ids.length; i++) {
        if (ids[i][0] !== '') {
          seen[String(ids[i][0])] = true;
        }
      }
    }

    // 3. Build the new rows in header order.
    var out = [];
    var skipped = 0;
    rows.forEach(function (data) {
      var id = data[idColumn] !== undefined ? String(data[idColumn]) : '';
      if (id !== '' && seen[id]) {
        skipped++;
        return;
      }
      if (id !== '') {
        seen[id] = true;
      }
      out.push(headers.map(function (h) {
        var v = Object.prototype.hasOwnProperty.call(data, h) ? data[h] : '';
        return v === null || v === undefined ? '' : v;
      }));
    });

    if (out.length) {
      sheet.getRange(sheet.getLastRow() + 1, 1, out.length, headers.length).setValues(out);
    }

    var msg = out.length + ' row(s) added to "' + name + '"' + (skipped ? ', ' + skipped + ' duplicate(s) skipped' : '');
    return json_({ status: 'success', message: msg, added: out.length, skipped: skipped, version: VERSION });
  } catch (err) {
    return json_({ status: 'error', message: String(err && err.message ? err.message : err), version: VERSION });
  } finally {
    lock.releaseLock();
  }
}

// Open the URL in a browser to check the deployment works.
function doGet() {
  return json_({ status: 'success', message: 'Webhook is live', version: VERSION });
}

function json_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
