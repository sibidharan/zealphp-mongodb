//! Structured encoding of a `mongodb::error::Error` so the PHP layer can
//! reconstruct a typed `MongoDB\Driver\Exception\*` carrying the real server
//! `code` / `codeName` / per-write errors — instead of a bare `\Exception`
//! with code 0 (cluster C1 / issue #38, #22, #41, #42, ...).
//!
//! The whole error string is framed with a sentinel so the PHP `ErrorMapper`
//! can recognise it; fields are SOH(`\x01`)-delimited. Head record:
//!
//!   \x01ZMERR\x01<kind>\x01<code>\x01<codeName>\x01<labels>\x01<message>
//!
//! followed by zero or more per-write records:
//!
//!   \x01WE\x01<index>\x01<code>\x01<codeName>\x01<errmsg>
//!
//! `kind` is one of: command | write | writeconcern | serverselection |
//! other. Non-server
//! errors (validation, pool, bson) never carry the sentinel and pass through
//! to PHP unchanged.

use mongodb::error::{Error, ErrorKind, WriteFailure};

pub const SENTINEL: &str = "\u{1}ZMERR\u{1}";

type WriteRec = (i64, i32, String, String); // (index, code, codeName, errmsg)

pub fn encode_mongo_error(e: &Error) -> String {
    let labels = {
        let mut v: Vec<String> = e.labels().iter().cloned().collect();
        v.sort();
        v.join(",")
    };
    let human = e.to_string();

    let (kind, code, code_name, write_errors): (&str, i32, String, Vec<WriteRec>) = match &*e.kind {
        ErrorKind::Command(ce) => ("command", ce.code, ce.code_name.clone(), Vec::new()),

        ErrorKind::Write(WriteFailure::WriteError(we)) => {
            let cn = we.code_name.clone().unwrap_or_default();
            (
                "write",
                we.code,
                cn.clone(),
                vec![(0, we.code, cn, we.message.clone())],
            )
        }

        ErrorKind::Write(WriteFailure::WriteConcernError(wce)) => {
            ("writeconcern", wce.code, wce.code_name.clone(), Vec::new())
        }

        // A failed single/many insert surfaces here. Only `index`/`code` are
        // relied on (both stable fields) to stay robust across crate versions.
        ErrorKind::InsertMany(ime) => {
            let mut top = 0i32;
            let mut recs = Vec::new();
            if let Some(list) = &ime.write_errors {
                for iwe in list {
                    if top == 0 {
                        top = iwe.code;
                    }
                    recs.push((iwe.index as i64, iwe.code, String::new(), String::new()));
                }
            }
            ("write", top, String::new(), recs)
        }

        // An unsatisfiable read preference / no-suitable-server is a server
        // selection failure; the official driver surfaces it as a
        // ConnectionTimeoutException carrying libmongoc's server-selection
        // error code 13053 (#67).
        ErrorKind::ServerSelection { .. } => ("serverselection", 13053, String::new(), Vec::new()),

        _ => ("other", 0, String::new(), Vec::new()),
    };

    let mut s = format!(
        "{SENTINEL}{kind}\u{1}{code}\u{1}{code_name}\u{1}{labels}\u{1}{human}",
    );
    for (index, c, cn, msg) in write_errors {
        s.push_str(&format!("\u{1}WE\u{1}{index}\u{1}{c}\u{1}{cn}\u{1}{msg}"));
    }
    s
}
