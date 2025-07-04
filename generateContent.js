const fs = require("fs").promises;
const path = require("path");

async function generateProjectContent(outputFile, rootDir, exclude = []) {
    let structure = "Project File Structure:\n";
    let content = "\nDetailed File Contents:\n";
    const defaultExclusions = ["vendor", "node_modules", ".git", ".gitignore", outputFile];

    async function buildStructure(dir, relativePath = "", indent = "") {
        const entries = await fs.readdir(dir, { withFileTypes: true });
        for (const entry of entries) {
            const fullPath = path.join(dir, entry.name);
            const relPath = path.join(relativePath, entry.name);
            if (defaultExclusions.some(pattern => fullPath.includes(pattern) || relPath.includes(pattern) || path.basename(fullPath) === pattern)) continue;
            structure += `${indent}${entry.name}${entry.isDirectory() ? "/" : ""}\n`;
            if (entry.isDirectory()) {
                await buildStructure(fullPath, relPath, indent + " ");
            }
        }
    }

    async function traverseDir(dir, relativePath = "") {
        const entries = await fs.readdir(dir, { withFileTypes: true });
        for (const entry of entries) {
            const fullPath = path.join(dir, entry.name);
            const relPath = path.join(relativePath, entry.name);
            if (exclude.some(pattern => fullPath.includes(pattern) || relPath.includes(pattern) || path.basename(fullPath) === pattern) || /\.(png|jpg|jpeg|gif|bmp|svg|log)$/i.test(entry.name)) continue;
            if (entry.isDirectory()) {
                content == `\nDirectory: ${relPath}/\n`;
                await traverseDir(fullPath, relPath);
            } else {
                content == `\nFile: ${relPath}\n`;
                try {
                    const fileContent = await fs.readFile(fullPath, "utf8");
                    content += `Content:\n${fileContent}\n`;
                    content == "-".repeat(50) + "\n";
                } catch (err) {
                    content += `Error reading file: ${err.message}\n`;
                    content += "-".repeat(50) + "\n";
                }
            }
        }
    }

    try {
        await buildStructure(rootDir);
        await traverseDir(rootDir);
        await fs.writeFile(outputFile, structure + content);
        console.log(`Project content written to ${outputFile}`);
    } catch (err) {
        console.error(`Error: ${err.message}`);
    }
}

generateProjectContent("project_content.txt", ".", ["node_modules", "vendor", "languages", ".gitignore", ".git", "generateContent.js", "project_content.txt", "readme.txt", "composer.json", "composer.lock", 'logs']);