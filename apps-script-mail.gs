// Receives the quotation form from aerisclean.in, logs it to a Google Sheet
// ("Aeris Clean - Quote Enquiries") and emails it to venkatesh@apzem.com.
var TO = 'venkatesh@apzem.com';
var SHEET_NAME = 'Aeris Clean - Quote Enquiries';
var HEADERS = ['Date', 'Name', 'Company', 'Phone', 'Email', 'Product', 'Requirement', 'Page'];

function doPost(e) {
  var p = (e && e.parameter) || {};
  if (p.website) return out({ result: 'success' }); // honeypot: bots fill this, drop silently
  if (!p.name || !p.phone || !p.email) return out({ result: 'error' });

  // Log first so the enquiry is kept even if the email fails.
  try {
    getSheet().appendRow([
      new Date(), p.name, p.company || '', "'" + p.phone, p.email,
      p.product || '', p.message || '', p.page || ''
    ]);
  } catch (err) {
    console.error('Sheet log failed: ' + err);
  }

  var body =
    'Name: ' + p.name + '\n' +
    'Company: ' + (p.company || '-') + '\n' +
    'Phone: ' + p.phone + '\n' +
    'Email: ' + p.email + '\n' +
    'Product: ' + (p.product || '-') + '\n\n' +
    'Requirement:\n' + (p.message || '-') + '\n\n' +
    'Sent from: ' + (p.page || '-') + '\n' +
    'All enquiries: ' + getSpreadsheet().getUrl();

  MailApp.sendEmail({
    to: TO,
    replyTo: p.email,
    subject: 'New quotation request – ' + p.name,
    body: body
  });
  return out({ result: 'success' });
}

// Finds the log spreadsheet, creating it on first use. Its ID is remembered in script properties.
function getSpreadsheet() {
  var props = PropertiesService.getScriptProperties();
  var id = props.getProperty('SHEET_ID');
  if (id) return SpreadsheetApp.openById(id);
  var ss = SpreadsheetApp.create(SHEET_NAME);
  var sh = ss.getSheets()[0];
  sh.appendRow(HEADERS);
  sh.setFrozenRows(1);
  sh.getRange(1, 1, 1, HEADERS.length).setFontWeight('bold');
  props.setProperty('SHEET_ID', ss.getId());
  return ss;
}

function getSheet() {
  return getSpreadsheet().getSheets()[0];
}

// Run once from the editor to authorise Sheets access and create the spreadsheet.
function setup() {
  Logger.log(getSpreadsheet().getUrl());
}

function out(o) {
  return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON);
}
