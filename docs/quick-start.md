# Quick start

This makes a feed that reads made-up books from our test-data repository into a **Books** collection. Swap in your own feed and collection as you go.

## 1. Somewhere for the items to go

Make a collection called Books with a blueprint that has these fields: **Title** (text), **ISBN** (text), **Pages** (integer) and **Description** (Bard).

## 2. Create the feed

Go to **Tools → Ingest → Create Feed**.

**Source.** Give it a name ("Books") and paste the feed URL:

```
https://raw.githubusercontent.com/1994limited/ingest-test-data/main/books/index.json
```

The **feed preview** on the right reads the feed straight away: it's JSON, with 60 items.

**Items.** The feed's structure shows `data` holds 60 items. Click it to make `data` the item path. Each item is now one book.

**Destination.** Choose **Entries**, the **Books** collection, and its blueprint. For the **key**, the value that identifies a book and never changes, click the ISBN field and then click `isbn` in the preview.

**Mapping.** Every field in the blueprint is listed. Click **Map by name** to fill in the obvious ones (title, isbn, pages), then open **Description** and choose `description_html`. The value each field would get for the book in the preview is shown beside it. Step through other books with the arrows.

![The mapping tab, with the feed preview beside it](images/cp-mapping.png)

**Save.**

## 3. A dry run

On the feed's page, click **Dry run**. Nothing is saved: the run page lists the 60 books it would create, field by field. When it looks right, click **Apply these changes**. Ingest imports exactly what you previewed.

## 4. Run it again

Click **Run now**. The run ends at once with "The feed hasn't changed since the last run": GitHub said so, so Ingest didn't even download it. Run it with **Run with options → Read the whole feed even if unchanged** and every book is **unchanged**: Ingest compared each one with what it saved last time and wrote nothing. When the feed does change, only the books that changed are updated, and only the fields you mapped.

## 5. Put it on a schedule

Edit the feed, open **Schedule & alerts**, and choose how often it runs (hourly at 5 past, say). Turn on an alert for failed runs. Make sure the [scheduler](installation.md#the-scheduler) is running.

## Next

- Feeds that need a token or have many pages: [Sources](sources.md).
- XML, RSS or CSV: [Formats and items](formats.md).
- What happens to books that leave the feed: [Sync rules](sync-rules.md).
- Images, authors and subjects: [Field types](field-types.md).
