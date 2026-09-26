// Tiny persistent store: jobs and seen event ids survive a runner restart.
import fs from 'node:fs';
import path from 'node:path';

export class Store {
  constructor(dir) {
    this.dir = dir;
    fs.mkdirSync(dir, { recursive: true });
    this.file = path.join(dir, 'state.json');
    this.data = { jobs: {}, receipts: {} };
    if (fs.existsSync(this.file)) {
      try {
        this.data = JSON.parse(fs.readFileSync(this.file, 'utf8'));
      } catch {
        fs.renameSync(this.file, this.file + '.corrupt-' + Date.now());
      }
    }
    this.data.jobs ||= {};
    this.data.receipts ||= {};
  }

  save() {
    const tmp = this.file + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(this.data));
    fs.renameSync(tmp, this.file);
  }

  // Returns false when the event id was already used (replay).
  claimEvent(eventId) {
    this.prune();
    if (this.data.receipts[eventId]) return false;
    this.data.receipts[eventId] = Date.now();
    this.save();
    return true;
  }

  key(runId, testId) {
    return `${runId}:${testId}`;
  }

  getJob(runId, testId) {
    return this.data.jobs[this.key(runId, testId)] || null;
  }

  putJob(job) {
    this.data.jobs[this.key(job.run_id, job.test_id)] = job;
    this.save();
    return job;
  }

  unfinished() {
    return Object.values(this.data.jobs).filter((j) => j.state !== 'done' || !j.delivered);
  }

  prune() {
    const cutoff = Date.now() - 7 * 24 * 3600 * 1000;
    for (const [k, t] of Object.entries(this.data.receipts)) if (t < cutoff) delete this.data.receipts[k];
    for (const [k, j] of Object.entries(this.data.jobs)) if (j.created_ms < cutoff) delete this.data.jobs[k];
  }
}
