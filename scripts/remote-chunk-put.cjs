const fs = require("fs");
const path = require("path");
const { Client } = require("ssh2");

const host = process.env.SSH_HOST || "15.232.137.74";
const username = process.env.SSH_USER || "ec2-user";
const password = process.env.SSH_PASS;
const remoteRoot = process.env.REMOTE_ROOT;
const files = process.argv.slice(2);
const chunkSize = Number(process.env.UPLOAD_CHUNK_SIZE || 6000);

if (!password || !remoteRoot || !files.length) {
  console.error("Usage: SSH_PASS=... REMOTE_ROOT=/remote/root node scripts/remote-chunk-put.cjs file...");
  process.exit(1);
}

function shellQuote(value) {
  return `'${String(value).replace(/'/g, `'\\''`)}'`;
}

function exec(conn, command) {
  return new Promise((resolve, reject) => {
    conn.exec(command, (err, stream) => {
      if (err) return reject(err);
      let stdout = "";
      let stderr = "";
      stream.on("data", (data) => {
        stdout += data.toString();
      });
      stream.stderr.on("data", (data) => {
        stderr += data.toString();
      });
      stream.on("close", (code) => {
        if (code) return reject(new Error(stderr || stdout || `command failed: ${command}`));
        resolve(stdout);
      });
    });
  });
}

async function putFile(conn, localFile, remoteFile) {
  const remoteDir = path.posix.dirname(remoteFile);
  const tempFile = `${remoteFile}.b64.${Date.now()}`;
  const encoded = fs.readFileSync(localFile).toString("base64");

  await exec(conn, `mkdir -p ${shellQuote(remoteDir)} && : > ${shellQuote(tempFile)}`);
  for (let index = 0; index < encoded.length; index += chunkSize) {
    await exec(conn, `printf %s ${shellQuote(encoded.slice(index, index + chunkSize))} >> ${shellQuote(tempFile)}`);
  }
  await exec(conn, `base64 -d ${shellQuote(tempFile)} > ${shellQuote(remoteFile)} && rm -f ${shellQuote(tempFile)}`);
}

const conn = new Client();
conn
  .on("ready", async () => {
    try {
      for (const file of files) {
        const localFile = path.resolve(file);
        const relative = path.relative(process.cwd(), localFile).replace(/\\/g, "/");
        const remoteFile = `${remoteRoot.replace(/\/$/, "")}/${relative}`;
        await putFile(conn, localFile, remoteFile);
        console.log(`${relative} -> ${remoteFile}`);
      }
      conn.end();
    } catch (error) {
      console.error(error.message);
      conn.end();
      process.exit(1);
    }
  })
  .on("error", (err) => {
    console.error(err.message);
    process.exit(1);
  })
  .connect({ host, username, password, readyTimeout: Number(process.env.SSH_READY_TIMEOUT_MS || 20000) });
