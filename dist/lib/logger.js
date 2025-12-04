/**
 * ロガーモジュール
 * winston を使用したログ出力
 */
import winston from 'winston';
import path from 'path';
import fs from 'fs';
const LOG_LEVEL = process.env.LOG_LEVEL || 'info';
const LOG_FILE = process.env.LOG_FILE || './logs/app.log';
// ログディレクトリを確保
const logDir = path.dirname(LOG_FILE);
if (!fs.existsSync(logDir)) {
    fs.mkdirSync(logDir, { recursive: true });
}
// ログフォーマット
const logFormat = winston.format.combine(winston.format.timestamp({ format: 'YYYY-MM-DD HH:mm:ss' }), winston.format.errors({ stack: true }), winston.format.printf(({ timestamp, level, message, ...meta }) => {
    let log = `${timestamp} [${level.toUpperCase()}] ${message}`;
    if (Object.keys(meta).length > 0) {
        log += ` ${JSON.stringify(meta)}`;
    }
    return log;
}));
// コンソール用フォーマット（カラー付き）
const consoleFormat = winston.format.combine(winston.format.colorize(), winston.format.timestamp({ format: 'HH:mm:ss' }), winston.format.printf(({ timestamp, level, message, ...meta }) => {
    let log = `${timestamp} ${level} ${message}`;
    if (Object.keys(meta).length > 0) {
        log += ` ${JSON.stringify(meta)}`;
    }
    return log;
}));
// ロガー作成
const logger = winston.createLogger({
    level: LOG_LEVEL,
    format: logFormat,
    transports: [
        // ファイル出力
        new winston.transports.File({
            filename: LOG_FILE,
            maxsize: 5242880, // 5MB
            maxFiles: 5,
            tailable: true,
        }),
        // エラーログは別ファイル
        new winston.transports.File({
            filename: LOG_FILE.replace('.log', '.error.log'),
            level: 'error',
            maxsize: 5242880,
            maxFiles: 5,
        }),
    ],
});
// 開発環境ではコンソールにも出力
if (process.env.NODE_ENV !== 'production') {
    logger.add(new winston.transports.Console({
        format: consoleFormat,
    }));
}
export default logger;
//# sourceMappingURL=logger.js.map