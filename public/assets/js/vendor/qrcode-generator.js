/*!
 * SMK Match QR SVG encoder v1.0.0 (pinned 2026-10-01)
 * Copyright (c) 2026 SMK Match contributors
 * SPDX-License-Identifier: MIT
 *
 * A small, local QR Code Model 2 byte-mode encoder. It supports versions
 * 1–10 at error-correction level L, which covers the configured APP_URL plus
 * a canonical SmartLink path. It has no network, package-manager, or CDN
 * dependency.
 */
(() => {
    "use strict";

    const ERROR_CORRECTION_LEVEL_L = 1;
    const RS_BLOCKS = [null, [{ total: 26, data: 19 }], [{ total: 44, data: 34 }], [{ total: 70, data: 55 }], [{ total: 100, data: 80 }], [{ total: 134, data: 108 }], [{ total: 86, data: 68 }, { total: 86, data: 68 }], [{ total: 98, data: 78 }, { total: 98, data: 78 }], [{ total: 121, data: 97 }, { total: 121, data: 97 }], [{ total: 146, data: 116 }, { total: 146, data: 116 }], [{ total: 86, data: 68 }, { total: 86, data: 68 }, { total: 87, data: 69 }, { total: 87, data: 69 }]];
    const ALIGNMENT_POSITIONS = [null, [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]];
    const EXP = new Array(512);
    const LOG = new Array(256);

    let fieldValue = 1;
    for (let index = 0; index < 255; index += 1) {
        EXP[index] = fieldValue;
        LOG[fieldValue] = index;
        fieldValue <<= 1;
        if ((fieldValue & 0x100) !== 0) {
            fieldValue ^= 0x11d;
        }
    }
    for (let index = 255; index < EXP.length; index += 1) {
        EXP[index] = EXP[index - 255];
    }

    function multiply(left, right) {
        return left === 0 || right === 0 ? 0 : EXP[LOG[left] + LOG[right]];
    }

    function multiplyPolynomials(left, right) {
        const result = new Array(left.length + right.length - 1).fill(0);
        left.forEach((leftValue, leftIndex) => right.forEach((rightValue, rightIndex) => {
            result[leftIndex + rightIndex] ^= multiply(leftValue, rightValue);
        }));
        return result;
    }

    function errorCorrection(data, length) {
        let polynomial = [1];
        for (let index = 0; index < length; index += 1) {
            polynomial = multiplyPolynomials(polynomial, [1, EXP[index]]);
        }

        const remainder = new Array(length).fill(0);
        data.forEach((byte) => {
            const factor = byte ^ remainder.shift();
            remainder.push(0);
            for (let index = 0; index < length; index += 1) {
                remainder[index] ^= multiply(polynomial[index + 1], factor);
            }
        });
        return remainder;
    }

    function bitLength(valueToMeasure) {
        let value = valueToMeasure;
        let length = 0;
        while (value !== 0) {
            length += 1;
            value >>>= 1;
        }
        return length;
    }

    function bchRemainder(valueToDivide, polynomial) {
        let value = valueToDivide;
        while (bitLength(value) >= bitLength(polynomial)) {
            value ^= polynomial << (bitLength(value) - bitLength(polynomial));
        }
        return value;
    }

    function typeInformation(maskPattern) {
        const data = (ERROR_CORRECTION_LEVEL_L << 3) | maskPattern;
        return ((data << 10) | bchRemainder(data << 10, 0x537)) ^ 0x5412;
    }

    function versionInformation(version) {
        return (version << 12) | bchRemainder(version << 12, 0x1f25);
    }

    class BitBuffer {
        constructor() {
            this.bits = [];
        }

        put(valueToAdd, length) {
            for (let index = length - 1; index >= 0; index -= 1) {
                this.bits.push(((valueToAdd >>> index) & 1) === 1);
            }
        }

        toBytes() {
            const bytes = new Array(Math.ceil(this.bits.length / 8)).fill(0);
            this.bits.forEach((bit, index) => {
                if (bit) {
                    bytes[Math.floor(index / 8)] |= 0x80 >>> (index % 8);
                }
            });
            return bytes;
        }
    }

    function selectVersion(byteLength) {
        for (let version = 1; version < RS_BLOCKS.length; version += 1) {
            const dataCapacity = RS_BLOCKS[version].reduce((total, block) => total + block.data, 0);
            const countBits = version < 10 ? 8 : 16;
            if ((4 + countBits + (byteLength * 8)) <= (dataCapacity * 8)) {
                return version;
            }
        }
        throw new RangeError("QR payload exceeds the local encoder capacity.");
    }

    function createCodewords(version, bytes) {
        const blocks = RS_BLOCKS[version];
        const capacity = blocks.reduce((total, block) => total + block.data, 0);
        const buffer = new BitBuffer();
        buffer.put(0x4, 4);
        buffer.put(bytes.length, version < 10 ? 8 : 16);
        bytes.forEach((byte) => buffer.put(byte, 8));

        if (buffer.bits.length + 4 <= capacity * 8) {
            buffer.put(0, 4);
        }
        while (buffer.bits.length % 8 !== 0) {
            buffer.put(0, 1);
        }

        const data = buffer.toBytes();
        let padIndex = 0;
        while (data.length < capacity) {
            data.push(padIndex % 2 === 0 ? 0xec : 0x11);
            padIndex += 1;
        }

        let offset = 0;
        const dataBlocks = blocks.map((block) => {
            const blockData = data.slice(offset, offset + block.data);
            offset += block.data;
            return blockData;
        });
        const correctionBlocks = blocks.map((block, index) => errorCorrection(dataBlocks[index], block.total - block.data));
        const codewords = [];
        const largestData = Math.max(...dataBlocks.map((block) => block.length));
        const largestCorrection = Math.max(...correctionBlocks.map((block) => block.length));

        for (let index = 0; index < largestData; index += 1) {
            dataBlocks.forEach((block) => {
                if (index < block.length) {
                    codewords.push(block[index]);
                }
            });
        }
        for (let index = 0; index < largestCorrection; index += 1) {
            correctionBlocks.forEach((block) => {
                if (index < block.length) {
                    codewords.push(block[index]);
                }
            });
        }
        return codewords;
    }

    function makeMatrix(version, codewords) {
        const size = (version * 4) + 17;
        const modules = Array.from({ length: size }, () => new Array(size).fill(null));

        function setProbePattern(row, column) {
            for (let rowOffset = -1; rowOffset <= 7; rowOffset += 1) {
                for (let columnOffset = -1; columnOffset <= 7; columnOffset += 1) {
                    const targetRow = row + rowOffset;
                    const targetColumn = column + columnOffset;
                    if (targetRow < 0 || targetRow >= size || targetColumn < 0 || targetColumn >= size) {
                        continue;
                    }
                    modules[targetRow][targetColumn] = rowOffset >= 0 && rowOffset <= 6 && columnOffset >= 0 && columnOffset <= 6
                        && (rowOffset === 0 || rowOffset === 6 || columnOffset === 0 || columnOffset === 6 || (rowOffset >= 2 && rowOffset <= 4 && columnOffset >= 2 && columnOffset <= 4));
                }
            }
        }

        function setAlignmentPatterns() {
            const positions = ALIGNMENT_POSITIONS[version];
            positions.forEach((row) => positions.forEach((column) => {
                if (modules[row][column] !== null) {
                    return;
                }
                for (let rowOffset = -2; rowOffset <= 2; rowOffset += 1) {
                    for (let columnOffset = -2; columnOffset <= 2; columnOffset += 1) {
                        modules[row + rowOffset][column + columnOffset] = Math.abs(rowOffset) === 2 || Math.abs(columnOffset) === 2 || (rowOffset === 0 && columnOffset === 0);
                    }
                }
            }));
        }

        function setTimingPatterns() {
            for (let index = 8; index < size - 8; index += 1) {
                if (modules[index][6] === null) {
                    modules[index][6] = index % 2 === 0;
                }
                if (modules[6][index] === null) {
                    modules[6][index] = index % 2 === 0;
                }
            }
        }

        function setVersionInformation() {
            if (version < 7) {
                return;
            }
            const information = versionInformation(version);
            for (let index = 0; index < 18; index += 1) {
                const dark = ((information >>> index) & 1) === 1;
                modules[Math.floor(index / 3)][(index % 3) + size - 11] = dark;
                modules[(index % 3) + size - 11][Math.floor(index / 3)] = dark;
            }
        }

        function setTypeInformation() {
            const information = typeInformation(0);
            for (let index = 0; index < 15; index += 1) {
                const dark = ((information >>> index) & 1) === 1;
                if (index < 6) {
                    modules[index][8] = dark;
                } else if (index < 8) {
                    modules[index + 1][8] = dark;
                } else {
                    modules[size - 15 + index][8] = dark;
                }
                if (index < 8) {
                    modules[8][size - index - 1] = dark;
                } else if (index < 9) {
                    modules[8][15 - index] = dark;
                } else {
                    modules[8][15 - index - 1] = dark;
                }
            }
            modules[size - 8][8] = true;
        }

        function mapData() {
            let byteIndex = 0;
            let bitIndex = 7;
            let upward = true;
            for (let column = size - 1; column > 0; column -= 2) {
                if (column === 6) {
                    column -= 1;
                }
                for (let offset = 0; offset < size; offset += 1) {
                    const row = upward ? size - 1 - offset : offset;
                    for (let columnOffset = 0; columnOffset < 2; columnOffset += 1) {
                        const targetColumn = column - columnOffset;
                        if (modules[row][targetColumn] !== null) {
                            continue;
                        }
                        const bit = byteIndex < codewords.length && ((codewords[byteIndex] >>> bitIndex) & 1) === 1;
                        modules[row][targetColumn] = bit !== ((row + targetColumn) % 2 === 0);
                        bitIndex -= 1;
                        if (bitIndex < 0) {
                            byteIndex += 1;
                            bitIndex = 7;
                        }
                    }
                }
                upward = !upward;
            }
        }

        setProbePattern(0, 0);
        setProbePattern(size - 7, 0);
        setProbePattern(0, size - 7);
        setAlignmentPatterns();
        setTimingPatterns();
        setVersionInformation();
        setTypeInformation();
        mapData();
        return modules;
    }

    function render(container, payload, options = {}) {
        if (!(container instanceof Element) || typeof payload !== "string" || payload === "") {
            throw new TypeError("A QR container and non-empty payload are required.");
        }
        const bytes = Array.from(new TextEncoder().encode(payload));
        const modules = makeMatrix(selectVersion(bytes.length), createCodewords(selectVersion(bytes.length), bytes));
        const size = modules.length;
        const foreground = typeof options.foreground === "string" ? options.foreground : "#000000";
        const background = typeof options.background === "string" ? options.background : "#ffffff";
        const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        const backgroundPath = document.createElementNS("http://www.w3.org/2000/svg", "path");
        const modulesPath = document.createElementNS("http://www.w3.org/2000/svg", "path");
        let path = "";

        modules.forEach((row, rowIndex) => row.forEach((dark, columnIndex) => {
            if (dark) {
                path += `M${columnIndex} ${rowIndex}h1v1h-1z`;
            }
        }));

        svg.setAttribute("viewBox", `0 0 ${size} ${size}`);
        svg.setAttribute("role", "img");
        svg.setAttribute("aria-label", "QR code");
        backgroundPath.setAttribute("fill", background);
        backgroundPath.setAttribute("d", `M0 0h${size}v${size}H0z`);
        modulesPath.setAttribute("fill", foreground);
        modulesPath.setAttribute("d", path);
        svg.append(backgroundPath, modulesPath);
        container.replaceChildren(svg);
    }

    window.SmkMatchQrSvg = Object.freeze({ render });
})();
