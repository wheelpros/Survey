# Deploy this as its own Coolify Application, separate from the PHP
# site - point Coolify's "Base Directory" at the mcp-server folder in
# the repo so this Dockerfile (not the PHP one at the repo root)
# builds it.

FROM node:20-alpine

WORKDIR /app

COPY package.json ./
RUN npm install --omit=dev

COPY src ./src

# Configuration comes from Coolify's Environment Variables panel at
# deploy time, not from a .env file baked into the image - .env.example
# documents what to set there, but is never copied in here.

EXPOSE 8787

CMD ["node", "src/index.js"]
