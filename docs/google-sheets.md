# Google Sheets

Choose **Google Sheets** as the source and paste the sheet's link from its **Share** button. Each row is an item and the first row names the columns, so map paths like `Staff member` or `Why we love it`.

## Public sheets

Share the sheet as **Anyone with the link can view**. Nothing else is needed: Ingest reads the sheet as CSV. The tab in the link (`#gid=…`) is the one read, unless you name another under **Tab name**.

If the sheet isn't shared like that, Google returns a sign-in page, and the run fails with an explanation rather than importing nothing.

## Private sheets

For a sheet that mustn't be public, use a Google service account:

1. In the [Google Cloud console](https://console.cloud.google.com/), create a project (or use one you have), enable the **Google Sheets API**, and create a **service account**.
2. Create a JSON key for it and save it on the server, outside the web root, for example `storage/app/private/google-sheets.json`. Never commit it.
3. Point Ingest at it in `.env`:

   ```env
   INGEST_GOOGLE_SERVICE_ACCOUNT=/home/forge/example.com/storage/app/private/google-sheets.json
   ```

4. Share the sheet with the service account's email address (`…@….iam.gserviceaccount.com`), as a viewer.
5. Tick **Private sheet** on the feed.

Ingest signs in as the service account with read-only access to sheets, keeps the token for most of its hour, and reads the values as the sheet shows them (formatted dates and numbers).

## Sheets as a CMS

A sheet editors already use makes an easy back end for small, structured content: staff picks, opening hours, a price list, a stockist directory. Run the feed every few minutes, or start it with the [webhook](running.md#the-webhook) from an Apps Script when the sheet changes. See the [recipe](recipes/sheets-cms.md).
