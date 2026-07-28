-- CreateTable
CREATE TABLE "product" (
    "identifier" TEXT NOT NULL,
    "label" JSONB,
    "image_filenames" JSONB,
    "categories" JSONB,
    "enabled" BOOLEAN NOT NULL DEFAULT false,
    "parent" TEXT,
    "parent_label" TEXT,
    "variation_label" TEXT,
    "description" TEXT,
    "completeness" DOUBLE PRECISION,
    "synced_at" TIMESTAMP(3),

    CONSTRAINT "product_pkey" PRIMARY KEY ("identifier")
);

-- CreateTable
CREATE TABLE "category" (
    "code" TEXT NOT NULL,
    "label" TEXT,

    CONSTRAINT "category_pkey" PRIMARY KEY ("code")
);

-- CreateTable
CREATE TABLE "sync_log" (
    "id" SERIAL NOT NULL,
    "synced_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "count" INTEGER NOT NULL,
    "trigger" TEXT NOT NULL,

    CONSTRAINT "sync_log_pkey" PRIMARY KEY ("id")
);
