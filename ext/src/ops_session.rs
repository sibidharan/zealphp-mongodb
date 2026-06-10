//! Session-aware op variants. Mirrors `ops.rs` but threads a registered
//! `ClientSession` through every server round-trip via the action API's
//! `.session(&mut s)` — required for transactions and causal consistency.
//!
//! Kept separate from `ops.rs` on purpose: the non-session hot path stays
//! byte-identical (zero regression surface), and the borrow patterns here
//! (cursor iteration must re-borrow the SAME session per batch) don't fit
//! the plain functions' shape.

use bson::raw::RawDocumentBuf;
use bson::Document;
use mongodb::Client;

use crate::coroutine;
use crate::session::SharedSession;

pub fn find_one(
    client: &Client, db: &str, col: &str, filter: Document,
    opts: mongodb::options::FindOneOptions, session: SharedSession,
) -> Result<Option<RawDocumentBuf>, String> {
    let collection = client.database(db).collection::<RawDocumentBuf>(col);
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        collection.find_one(filter).with_options(opts).session(&mut *guard).await
    })
}

/// Session reads return a `SessionCursor` whose every batch fetch needs the
/// same `&mut session` — so the result set is collected EAGERLY inside the
/// borrow and returned whole. (Within a transaction the snapshot is bounded
/// anyway; documented in Collection::find().)
pub fn find_all(
    client: &Client, db: &str, col: &str, filter: Document,
    opts: mongodb::options::FindOptions, session: SharedSession,
) -> Result<Vec<RawDocumentBuf>, String> {
    let collection = client.database(db).collection::<RawDocumentBuf>(col);
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        let mut cursor = collection.find(filter).with_options(opts).session(&mut *guard).await?;
        let mut docs = Vec::new();
        while let Some(doc) = cursor.next(&mut *guard).await.transpose()? {
            docs.push(doc);
        }
        Ok::<_, mongodb::error::Error>(docs)
    })
}

pub fn aggregate_all(
    client: &Client, db: &str, col: &str, pipeline: Vec<Document>, session: SharedSession,
) -> Result<Vec<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        let mut cursor = collection.aggregate(pipeline).session(&mut *guard).await?;
        let mut docs = Vec::new();
        while let Some(doc) = cursor.next(&mut *guard).await.transpose()? {
            docs.push(doc);
        }
        Ok::<_, mongodb::error::Error>(docs)
    })
}

pub fn insert_one(
    client: &Client, db: &str, col: &str, doc: Document, session: SharedSession,
) -> Result<mongodb::results::InsertOneResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        collection.insert_one(doc).session(&mut *guard).await
    })
}

pub fn insert_many(
    client: &Client, db: &str, col: &str, docs: Vec<Document>, session: SharedSession,
) -> Result<mongodb::results::InsertManyResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        collection.insert_many(docs).session(&mut *guard).await
    })
}

pub fn update_one(
    client: &Client, db: &str, col: &str, filter: Document, update: Document,
    opts: mongodb::options::UpdateOptions, session: SharedSession,
) -> Result<mongodb::results::UpdateResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        collection.update_one(filter, update).with_options(opts).session(&mut *guard).await
    })
}

pub fn update_many(
    client: &Client, db: &str, col: &str, filter: Document, update: Document,
    opts: mongodb::options::UpdateOptions, session: SharedSession,
) -> Result<mongodb::results::UpdateResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        collection.update_many(filter, update).with_options(opts).session(&mut *guard).await
    })
}

pub fn delete_one(
    client: &Client, db: &str, col: &str, filter: Document, session: SharedSession,
) -> Result<mongodb::results::DeleteResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        collection.delete_one(filter).session(&mut *guard).await
    })
}

pub fn delete_many(
    client: &Client, db: &str, col: &str, filter: Document, session: SharedSession,
) -> Result<mongodb::results::DeleteResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        collection.delete_many(filter).session(&mut *guard).await
    })
}

pub fn replace_one(
    client: &Client, db: &str, col: &str, filter: Document, replacement: Document,
    opts: mongodb::options::ReplaceOptions, session: SharedSession,
) -> Result<mongodb::results::UpdateResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        collection.replace_one(filter, replacement).with_options(opts).session(&mut *guard).await
    })
}

pub fn count_documents(
    client: &Client, db: &str, col: &str, filter: Document, session: SharedSession,
) -> Result<u64, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        collection.count_documents(filter).session(&mut *guard).await
    })
}

pub fn distinct(
    client: &Client, db: &str, col: &str, field_name: &str, filter: Document, session: SharedSession,
) -> Result<Vec<bson::Bson>, String> {
    let collection = client.database(db).collection::<Document>(col);
    let field = field_name.to_string();
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        collection.distinct(&field, filter).session(&mut *guard).await
    })
}

pub fn find_one_and_update(
    client: &Client, db: &str, col: &str, filter: Document, update: Document,
    opts: mongodb::options::FindOneAndUpdateOptions, session: SharedSession,
) -> Result<Option<RawDocumentBuf>, String> {
    let collection = client.database(db).collection::<RawDocumentBuf>(col);
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        collection.find_one_and_update(filter, update).with_options(opts).session(&mut *guard).await
    })
}

pub fn find_one_and_delete(
    client: &Client, db: &str, col: &str, filter: Document, session: SharedSession,
) -> Result<Option<RawDocumentBuf>, String> {
    let collection = client.database(db).collection::<RawDocumentBuf>(col);
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        collection.find_one_and_delete(filter).session(&mut *guard).await
    })
}

pub fn find_one_and_replace(
    client: &Client, db: &str, col: &str, filter: Document, replacement: Document,
    opts: mongodb::options::FindOneAndReplaceOptions, session: SharedSession,
) -> Result<Option<RawDocumentBuf>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        match collection
            .find_one_and_replace(filter, replacement)
            .with_options(opts)
            .session(&mut *guard)
            .await?
        {
            Some(doc) => {
                let raw = RawDocumentBuf::from_document(&doc)
                    .map_err(mongodb::error::Error::custom)?;
                Ok::<_, mongodb::error::Error>(Some(raw))
            }
            None => Ok(None),
        }
    })
}
