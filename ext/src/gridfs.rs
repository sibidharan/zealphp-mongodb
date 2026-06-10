//! GridFS — byte-oriented bridge over the driver's `GridFsBucket`.
//!
//! The PHP wrapper exposes the mongo-php-library `Bucket` API
//! (uploadFromStream / downloadToStream / open*Stream via php://temp
//! buffers); this module moves whole byte payloads per call. Chunking to
//! the `fs.chunks` collection (255 KiB default) happens inside the driver.

use bson::{Bson, Document};
use futures::io::{AsyncReadExt, AsyncWriteExt};
use mongodb::options::GridFsBucketOptions;

use crate::coroutine;
use crate::pool;

fn bucket_options(bucket_name: Option<&str>, chunk_size: Option<u32>) -> GridFsBucketOptions {
    let mut opts = GridFsBucketOptions::default();
    if let Some(name) = bucket_name {
        opts.bucket_name = Some(name.to_string());
    }
    if let Some(size) = chunk_size {
        opts.chunk_size_bytes = Some(size);
    }
    opts
}

/// Upload a whole payload; returns the file id (ObjectId Bson).
pub fn upload(
    pool_id: u64,
    db: &str,
    bucket_name: Option<&str>,
    filename: &str,
    data: Vec<u8>,
    metadata: Option<Document>,
    chunk_size: Option<u32>,
    preset_id: Option<Bson>,
) -> Result<Bson, String> {
    let client = pool::get_client(pool_id)?;
    let database = client.database(db);
    let bucket = database.gridfs_bucket(bucket_options(bucket_name, chunk_size));
    let filename = filename.to_string();

    coroutine::run_sync(async move {
        let mut action = bucket.open_upload_stream(&filename);
        if let Some(meta) = metadata {
            action = action.metadata(meta);
        }
        if let Some(id) = preset_id {
            action = action.id(id);
        }
        let mut stream = action.await?;
        let id = stream.id().clone();
        stream.write_all(&data).await.map_err(mongodb::error::Error::custom)?;
        stream.close().await.map_err(mongodb::error::Error::custom)?;
        Ok::<_, mongodb::error::Error>(id)
    })
}

/// Download a whole file by id.
pub fn download(
    pool_id: u64,
    db: &str,
    bucket_name: Option<&str>,
    id: Bson,
) -> Result<Vec<u8>, String> {
    let client = pool::get_client(pool_id)?;
    let database = client.database(db);
    let bucket = database.gridfs_bucket(bucket_options(bucket_name, None));

    coroutine::run_sync(async move {
        let mut stream = bucket.open_download_stream(id).await?;
        let mut buf = Vec::new();
        stream.read_to_end(&mut buf).await.map_err(mongodb::error::Error::custom)?;
        Ok::<_, mongodb::error::Error>(buf)
    })
}

/// Download the latest (or `revision`-th) file with this name.
pub fn download_by_name(
    pool_id: u64,
    db: &str,
    bucket_name: Option<&str>,
    filename: &str,
    revision: Option<i32>,
) -> Result<Vec<u8>, String> {
    let client = pool::get_client(pool_id)?;
    let database = client.database(db);
    let bucket = database.gridfs_bucket(bucket_options(bucket_name, None));
    let filename = filename.to_string();

    coroutine::run_sync(async move {
        let mut action = bucket.open_download_stream_by_name(&filename);
        if let Some(rev) = revision {
            action = action.revision(rev);
        }
        let mut stream = action.await?;
        let mut buf = Vec::new();
        stream.read_to_end(&mut buf).await.map_err(mongodb::error::Error::custom)?;
        Ok::<_, mongodb::error::Error>(buf)
    })
}

pub fn delete(pool_id: u64, db: &str, bucket_name: Option<&str>, id: Bson) -> Result<(), String> {
    let client = pool::get_client(pool_id)?;
    let database = client.database(db);
    let bucket = database.gridfs_bucket(bucket_options(bucket_name, None));
    coroutine::run_sync(async move { bucket.delete(id).await })
}

pub fn rename(
    pool_id: u64,
    db: &str,
    bucket_name: Option<&str>,
    id: Bson,
    new_filename: &str,
) -> Result<(), String> {
    let client = pool::get_client(pool_id)?;
    let database = client.database(db);
    let bucket = database.gridfs_bucket(bucket_options(bucket_name, None));
    let new_filename = new_filename.to_string();
    coroutine::run_sync(async move { bucket.rename(id, &new_filename).await })
}

pub fn drop(pool_id: u64, db: &str, bucket_name: Option<&str>) -> Result<(), String> {
    let client = pool::get_client(pool_id)?;
    let database = client.database(db);
    let bucket = database.gridfs_bucket(bucket_options(bucket_name, None));
    coroutine::run_sync(async move { bucket.drop().await })
}
