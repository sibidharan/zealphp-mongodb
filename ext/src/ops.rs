use bson::Document;
use mongodb::Client;

use crate::coroutine;

pub fn find_one(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
) -> Result<Option<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_async(async move { collection.find_one(filter).await })
}

pub fn find(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
) -> Result<mongodb::Cursor<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_async(async move { collection.find(filter).await })
}

pub fn insert_one(
    client: &Client,
    db: &str,
    col: &str,
    doc: Document,
) -> Result<mongodb::results::InsertOneResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_async(async move { collection.insert_one(doc).await })
}

pub fn update_one(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
    update: Document,
) -> Result<mongodb::results::UpdateResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_async(async move { collection.update_one(filter, update).await })
}

pub fn delete_one(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
) -> Result<mongodb::results::DeleteResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_async(async move { collection.delete_one(filter).await })
}

pub fn count_documents(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
) -> Result<u64, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_async(async move { collection.count_documents(filter).await })
}

pub fn aggregate(
    client: &Client,
    db: &str,
    col: &str,
    pipeline: Vec<Document>,
) -> Result<mongodb::Cursor<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_async(async move { collection.aggregate(pipeline).await })
}
