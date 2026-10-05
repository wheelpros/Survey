# Deploy this as its own Coolify Application, separate from the PHP
# site - point Coolify's "Base Directory" at the Wzone-ai folder in the
# repo so this Dockerfile (not the PHP one at the repo root) builds it.

# Node 20 left support in April 2026; 24 is the current LTS line.
FROM node:24-alpine

ENV NODE_ENV=production

WORKDIR /app

# Lockfile-exact install, production deps only - the build gets the same
# dependency tree the tests ran against.
COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

COPY src ./src

# Configuration comes from Coolify's Environment Variables panel at
# deploy time, not from a .env file baked into the image - env.example
# documents what to set there, but is never copied in here.

# The base image ships an unprivileged 'node' user; nothing here needs
# root once the install is done.
USER node

EXPOSE 8787

# Liveness only (/healthz never calls PHP), so a PHP outage doesn't get
# this container restarted for nothing. /readyz is the upstream check.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:' + (process.env.MCP_PORT || 8787) + '/healthz').then(r => process.exit(r.ok ? 0 : 1), () => process.exit(1))"

CMD ["node", "src/index.js"]
